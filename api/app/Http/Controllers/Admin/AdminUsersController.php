<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\AdminAccountException;
use App\Domain\Platform\AdminAccountService;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Superadmin-only admin account management (EnsureSuperadmin gates the
 * routes). No DELETE — deactivation is the only removal, so every audit
 * column referencing an admin keeps resolving. The temporary password is
 * returned exactly once, on creation, and never again.
 */
class AdminUsersController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AdminUser::query()
                ->orderBy('id')
                ->get()
                ->map(fn (AdminUser $admin) => $this->payload($admin))
                ->values(),
        ]);
    }

    public function store(Request $request, AdminAccountService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admin_users', 'email')],
            'role' => ['sometimes', 'string', Rule::in(['admin', 'superadmin'])],
        ]);

        [$admin, $tempPassword] = $service->create(
            $validated['name'],
            $validated['email'],
            $validated['role'] ?? 'admin',
        );

        return response()->json([
            'data' => $this->payload($admin),
            // Shown once; the hash is all that survives.
            'temp_password' => $tempPassword,
        ], 201);
    }

    public function update(Request $request, int $id, AdminAccountService $service): JsonResponse
    {
        $target = AdminUser::query()->findOrFail($id);

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(['admin', 'superadmin'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $service->update(
                $target,
                $request->user('admin'),
                $validated['role'] ?? null,
                array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
            );
        } catch (AdminAccountException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($target->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AdminUser $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'is_active' => $admin->is_active,
            'created_at' => $admin->created_at?->toIso8601String(),
        ];
    }
}
