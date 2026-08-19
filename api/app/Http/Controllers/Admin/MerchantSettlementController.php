<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Marketplace\MerchantPayoutBuilder;
use App\Domain\Marketplace\MerchantSheetImporter;
use App\Domain\Marketplace\MerchantTransferSheet;
use App\Domain\Transfers\BatchNotSendableException;
use App\Domain\Transfers\TransferClient;
use App\Domain\Transfers\TransferProfileRef;
use App\Http\Controllers\Controller;
use App\Jobs\SendMerchantPayoutBatchViaApi;
use App\Jobs\SendOneSettlementItemViaApi;
use App\Models\AdminUser;
use App\Models\MerchantPayoutBatch;
use App\Models\MerchantPayoutItem;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Merchant Settlements — its own menu, as the owner asked
 * (PLAN-marketplace.md §5.5).
 *
 * Kept apart from the cashback settlements screen on purpose, because the
 * money points the other way: there a merchant pays US, here we pay THEM.
 * One screen showing both directions would be a screen nobody can check.
 *
 * The workflow is the customer payout one, deliberately unchanged: build →
 * approve → export xlsx → bank → import the filled sheet → done. Whoever
 * does transfers should not have to learn a second process because the money
 * is going to a shop.
 */
final class MerchantSettlementController extends Controller
{
    public function __construct(
        private readonly MerchantPayoutBuilder $builder,
        private readonly MerchantTransferSheet $sheet,
        private readonly MerchantSheetImporter $importer,
        private readonly TransferClient $client,
    ) {}

    public function index(): JsonResponse
    {
        $batches = MerchantPayoutBatch::query()
            ->withCount('items')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return new JsonResponse([
            'data' => $batches->map(fn (MerchantPayoutBatch $batch): array => [
                'id' => $batch->id,
                'reference' => $batch->reference,
                'state' => $batch->state,
                'total_laari' => $batch->total_laari,
                'merchant_count' => $batch->merchant_count,
                'excluded_laari' => $batch->excluded_laari,
                'excluded_count' => $batch->excluded_count,
                'cutoff_at' => $batch->cutoff_at?->toIso8601String(),
                'approved_at' => $batch->approved_at?->toIso8601String(),
                'exported_at' => $batch->exported_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                // What a batch built right now would pick up — so an admin
                // can see whether it is worth running one.
                'payable_now_laari' => (int) $this->builder
                    ->payableSuborders(CarbonImmutable::now('UTC'))
                    ->sum('suborders.payable_to_merchant_laari'),
            ],
        ]);
    }

    public function show(MerchantPayoutBatch $batch): JsonResponse
    {
        return new JsonResponse(['data' => [
            'id' => $batch->id,
            'reference' => $batch->reference,
            'state' => $batch->state,
            'total_laari' => $batch->total_laari,
            'items' => $batch->items()->with('merchant:id,name,slug')->orderBy('id')->get()
                ->map(fn (MerchantPayoutItem $item): array => [
                    'id' => $item->id,
                    'merchant_name' => $item->merchant_name,
                    'amount_laari' => $item->amount_laari,
                    'bank' => $item->bank,
                    'account' => $item->account,
                    'account_name' => $item->account_name,
                    'internal_ref' => $item->internal_ref,
                    'state' => $item->state,
                    'trx_id' => $item->trx_id,
                    'approval_id' => $item->approval_id,
                    'failure_reason' => $item->failure_reason,
                    'order_count' => $item->suborders()->count(),
                ])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $batch = $this->builder->build($admin);

        return new JsonResponse(['data' => ['id' => $batch->id, 'reference' => $batch->reference]], 201);
    }

    public function approve(Request $request, MerchantPayoutBatch $batch): JsonResponse
    {
        if ($batch->state !== 'draft') {
            return new JsonResponse(['message' => 'Only a draft batch can be approved.', 'code' => 'not_draft'], 409);
        }

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $batch->forceFill([
            'state' => 'approved',
            'approved_by' => $admin->getKey(),
            'approved_at' => CarbonImmutable::now(),
        ])->save();

        return new JsonResponse(['data' => ['state' => 'approved']]);
    }

    /** The sheet finance takes to the bank. */
    public function export(MerchantPayoutBatch $batch): Response
    {
        if (! in_array($batch->state, ['approved', 'processing', 'completed'], true)) {
            abort(409, 'Approve the batch before exporting it.');
        }

        $content = $this->sheet->format($batch);

        if ($batch->exported_at === null) {
            $batch->forceFill(['exported_at' => CarbonImmutable::now()])->save();
        }

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s.xlsx"', $batch->reference),
        ]);
    }

    /** The filled sheet coming back. */
    public function import(Request $request, MerchantPayoutBatch $batch): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:5120'],
        ]);

        try {
            $result = $this->importer->import($batch, $request->file('file')->getRealPath());
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'import_failed'], 422);
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * Hand the whole run to a queue worker, which transfers every pending
     * row through the bank API (owner requirement 2026-08-19).
     *
     * A refusal does not stop the pass and is never retried — the worker
     * records why and moves to the next shop. Each row carries the same
     * `internal_ref` the sheet is matched on, so a worker that dies halfway
     * and is re-run cannot pay anybody twice.
     */
    public function sendBatch(Request $request, MerchantPayoutBatch $batch): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (! in_array($batch->state, ['approved', 'processing'], true)) {
            return new JsonResponse([
                'message' => 'Approve the run before sending it to the bank.',
                'code' => 'not_sendable',
            ], 409);
        }

        try {
            // Resolved here too, so a missing profile refuses to the admin's
            // face rather than dying quietly in a worker.
            TransferProfileRef::resolve($validated['profile_id'] ?? null);
        } catch (BatchNotSendableException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'no_profile'], 422);
        }

        SendMerchantPayoutBatchViaApi::dispatch((int) $batch->getKey(), $validated['profile_id'] ?? null);

        return new JsonResponse(['data' => [
            'queued' => $batch->items()->where('state', 'pending')->count(),
        ]]);
    }

    /**
     * Send one item through the bank API instead of the sheet.
     *
     * Same interpretation rules as the customer side — a parked transfer is
     * never re-sent, and a duplicate that already succeeded is adopted
     * rather than repeated.
     */
    public function send(Request $request, MerchantPayoutBatch $batch, MerchantPayoutItem $item): JsonResponse
    {
        abort_unless($item->batch_id === $batch->id, 404);

        if ($item->isParked()) {
            return new JsonResponse([
                'message' => 'This transfer is already waiting for a second approver.',
                'code' => 'pending_approval',
            ], 409);
        }

        if (! in_array($item->state, MerchantPayoutItem::SENDABLE, true)) {
            return new JsonResponse([
                'message' => sprintf('A payout that is %s cannot be sent.', $item->state),
                'code' => 'not_sendable',
            ], 409);
        }

        $settings = TransferSetting::current();
        $profile = $settings->profile_id !== null
            ? TransferProfile::query()->where('active', true)->find($settings->profile_id)
            : TransferProfile::query()->where('active', true)->where('is_default', true)->first();

        if ($profile === null) {
            return new JsonResponse(['message' => 'No transfer profile is configured.', 'code' => 'no_profile'], 409);
        }

        // Claimed HERE, before anything is queued, so a second press is
        // refused at the door rather than racing in a worker.
        $item->forceFill(['state' => 'processing', 'attempts' => $item->attempts + 1])->save();

        // Queued, not called here: a transfer can take two minutes and nginx
        // hangs up at sixty seconds.
        SendOneSettlementItemViaApi::dispatch(
            (int) $item->getKey(),
            (int) $profile->getKey(),
            'Manfaa settlement '.$batch->reference,
        );

        $fresh = $item->fresh();

        return new JsonResponse(['data' => [
            'state' => $fresh->state,
            'trx_id' => $fresh->trx_id,
            'approval_id' => $fresh->approval_id,
            'queued' => true,
        ]], 202);
    }

    public function cancel(MerchantPayoutBatch $batch): JsonResponse
    {
        try {
            $this->builder->cancel($batch);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'not_cancellable'], 409);
        }

        return new JsonResponse(['data' => ['state' => 'cancelled']]);
    }
}
