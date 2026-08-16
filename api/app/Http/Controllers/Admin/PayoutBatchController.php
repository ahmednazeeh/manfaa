<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payout\ApprovalService;
use App\Domain\Payout\BankFileExporter;
use App\Domain\Payout\CutoffInFutureException;
use App\Domain\Payout\DuplicatePayoutBatchException;
use App\Domain\Payout\ImportRowException;
use App\Domain\Payout\InvalidPayoutBatchStateException;
use App\Domain\Payout\InvalidPayoutItemStateException;
use App\Domain\Payout\PayoutBatchBuilder;
use App\Domain\Payout\PayoutItemSettler;
use App\Domain\Payout\TransferSheetImporter;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutBatchResource;
use App\Models\AdminUser;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PayoutBatchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PayoutBatchResource::collection(
            PayoutBatch::query()->orderByDesc('id')->paginate(25),
        );
    }

    public function store(Request $request, PayoutBatchBuilder $builder): JsonResponse
    {
        $validated = $request->validate([
            'cutoff_date' => ['required', 'date_format:Y-m-d'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $batch = $builder->buildDraft($this->cutoffInstant($validated['cutoff_date']), $admin);
        } catch (CutoffInFutureException $e) {
            abort(422, $e->getMessage());
        } catch (DuplicatePayoutBatchException $e) {
            abort(409, $e->getMessage());
        }

        return (new PayoutBatchResource($batch->load('items')))
            ->response($request)
            ->setStatusCode(201);
    }

    public function show(PayoutBatch $batch): PayoutBatchResource
    {
        return new PayoutBatchResource($batch->load('items'));
    }

    public function approve(Request $request, PayoutBatch $batch, ApprovalService $approvals): PayoutBatchResource
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $approvals->approve($batch, $admin);
        } catch (InvalidPayoutBatchStateException $e) {
            abort(409, $e->getMessage());
        }

        return new PayoutBatchResource($batch->refresh());
    }

    public function export(PayoutBatch $batch, BankFileExporter $exporter): Response
    {
        try {
            $content = $exporter->export($batch);
        } catch (InvalidPayoutBatchStateException $e) {
            abort(409, $e->getMessage());
        }

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s.xlsx"', $batch->reference),
        ]);
    }

    public function import(Request $request, PayoutBatch $batch, TransferSheetImporter $importer): PayoutBatchResource
    {
        $request->validate([
            // The filled transfer sheet: an xlsx, or the same sheet saved as
            // CSV. Gated on the extension rather than the sniffed type —
            // an xlsx is a zip and reports itself as one on some hosts — and
            // 5 MB is orders of magnitude above any real batch. What the file
            // actually is remains the reader's ruling, not the validator's.
            'file' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:5120'],
        ]);

        try {
            $importer->import($batch, $request->file('file')->getRealPath());
        } catch (ImportRowException $e) {
            abort(422, $e->getMessage());
        } catch (InvalidPayoutBatchStateException $e) {
            abort(409, $e->getMessage());
        }

        return new PayoutBatchResource($batch->refresh()->load('items'));
    }

    public function markPaid(Request $request, PayoutBatch $batch, PayoutItem $item, PayoutItemSettler $settler): PayoutBatchResource
    {
        $validated = $request->validate([
            'bank_reference' => ['required', 'string', 'max:255'],
        ]);

        try {
            $settler->settleOne($item, $validated['bank_reference']);
        } catch (InvalidPayoutItemStateException $e) {
            abort(422, $e->getMessage());
        } catch (InvalidPayoutBatchStateException $e) {
            abort(409, $e->getMessage());
        }

        return new PayoutBatchResource($batch->refresh()->load('items'));
    }

    public function markFailed(Request $request, PayoutBatch $batch, PayoutItem $item, PayoutItemSettler $settler): PayoutBatchResource
    {
        $validated = $request->validate([
            'failure_reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $settler->failOne($item, $validated['failure_reason']);
        } catch (InvalidPayoutItemStateException $e) {
            abort(422, $e->getMessage());
        } catch (InvalidPayoutBatchStateException $e) {
            abort(409, $e->getMessage());
        }

        return new PayoutBatchResource($batch->refresh()->load('items'));
    }

    public function settleAll(Request $request, PayoutBatch $batch, PayoutItemSettler $settler): PayoutBatchResource
    {
        $validated = $request->validate([
            'bank_reference' => ['required', 'string', 'max:255'],
        ]);

        try {
            $settler->settleAll($batch, $validated['bank_reference']);
        } catch (InvalidPayoutBatchStateException $e) {
            abort(409, $e->getMessage());
        }

        return new PayoutBatchResource($batch->refresh()->load('items'));
    }

    public function cancel(PayoutBatch $batch, PayoutBatchBuilder $builder): PayoutBatchResource
    {
        try {
            $builder->cancelDraft($batch);
        } catch (InvalidPayoutBatchStateException $e) {
            abort(409, $e->getMessage());
        }

        return new PayoutBatchResource($batch->refresh());
    }

    /**
     * The chosen day, ending at its last instant in business time — except
     * today, which ends now. Read literally, today's batch would always be
     * built ahead of its own cutoff and refused, and today is the run the
     * admin reaches for; taking the clock instead records a cutoff that is
     * exactly what the batch swept, and leaves any later date ahead of the
     * clock for the domain to refuse.
     */
    private function cutoffInstant(string $date): CarbonImmutable
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $now = CarbonImmutable::now($timezone);
        $cutoff = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->endOfDay();

        return $cutoff->isSameDay($now) ? $now : $cutoff;
    }
}
