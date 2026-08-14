<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payout\ApprovalService;
use App\Domain\Payout\BankFileExporter;
use App\Domain\Payout\DuplicatePayoutBatchException;
use App\Domain\Payout\ImportRowException;
use App\Domain\Payout\InvalidPayoutBatchStateException;
use App\Domain\Payout\PayoutBatchBuilder;
use App\Domain\Payout\ResultImporter;
use App\Domain\Payout\SameApproverException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutBatchResource;
use App\Models\AdminUser;
use App\Models\PayoutBatch;
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
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $batch = $builder->buildDraft((int) $validated['year'], (int) $validated['month'], $admin);
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
        } catch (SameApproverException $e) {
            abort(422, $e->getMessage());
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
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s.csv"', $batch->reference),
        ]);
    }

    public function import(Request $request, PayoutBatch $batch, ResultImporter $importer): PayoutBatchResource
    {
        $request->validate([
            // The bank's result file: small, plain CSV. 1 MB is orders of
            // magnitude above any real batch and blocks accidental uploads.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);

        try {
            $importer->importCsv($batch, $request->file('file')->getContent());
        } catch (ImportRowException $e) {
            abort(422, $e->getMessage());
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
}
