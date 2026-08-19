<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\Bank;
use App\Domain\Platform\BankAccountService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformBankAccountResource;
use App\Models\PlatformBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD over the platform's own bank accounts — where merchants send
 * settlement transfers. No DELETE: accounts deactivate, so old settlement
 * instructions stay explicable — and for the same reason account_no is
 * immutable once created (replace = create + deactivate). Writes are
 * superadmin-only (EnsureSuperadmin on the routes) and stamp the acting
 * admin; reading stays open to every admin. Exactly one active primary is
 * enforced by the service (promotion demotes the incumbent) and by a
 * partial unique index underneath it.
 */
class PlatformBankAccountsController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlatformBankAccountResource::collection(
            PlatformBankAccount::query()->orderByDesc('is_primary')->orderBy('id')->get(),
        );
    }

    public function store(Request $request, BankAccountService $service): JsonResponse
    {
        $validated = $request->validate([
            'bank_name' => ['required', Rule::enum(Bank::class)],
            'account_no' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'in:MVR'],
            'is_primary' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $account = $service->create($validated, $request->user('admin'));

        return (new PlatformBankAccountResource($account))
            ->response($request)
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id, BankAccountService $service): PlatformBankAccountResource
    {
        $account = PlatformBankAccount::query()->findOrFail($id);

        $validated = $request->validate([
            'bank_name' => ['sometimes', Rule::enum(Bank::class)],
            'account_no' => ['sometimes', 'string', 'max:255'],
            'account_name' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'in:MVR'],
            'is_primary' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return new PlatformBankAccountResource(
            $service->update($account, $validated, $request->user('admin')),
        );
    }
}
