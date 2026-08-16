<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\MerchantAccess\Permission;
use App\Domain\MerchantAccess\PermissionGroup;
use App\Domain\MerchantSettings\RoleException;
use App\Domain\MerchantSettings\RoleService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantRoleResource;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Settings › Roles: the permission catalogue the screen renders, and the
 * merchant's own roles (PLAN §13b staff permissions).
 *
 * Reading needs `roles.view`, writing `roles.manage`. The rules that decide
 * WHAT may be written live in RoleService, not here: they are the security
 * core of the round, the staff screen needs the same answers when it points
 * an account at a role, and a rule enforced in a controller is a rule the
 * next controller forgets.
 *
 * Every {id} resolves through the authenticated merchant's own rows, so
 * another store's role is indistinguishable from a missing one — role ids
 * are shared sequential integers, which is a bug class per-merchant roles
 * invite and literal tier strings never could.
 */
class RolesController extends Controller
{
    /**
     * The catalogue itself, grouped as the roles screen stacks it.
     *
     * Published rather than hardcoded in the panel (D8) so a permission
     * added by a later deploy renders — with its own wording and under the
     * right heading — in a panel build that predates it. The alternative is
     * a screen that silently omits the checkbox for a permission the server
     * is already enforcing.
     */
    public function permissions(): JsonResponse
    {
        $groups = array_map(fn (PermissionGroup $group): array => [
            'slug' => $group->value,
            'label' => $group->label(),
            'permissions' => array_map(fn (Permission $permission): array => [
                'slug' => $permission->value,
                'label' => $permission->label(),
                'group' => $group->value,
            ], $group->permissions()),
        ], PermissionGroup::cases());

        return new JsonResponse(['data' => ['groups' => $groups]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = MerchantRole::query()
            ->where('merchant_id', $this->merchant($request)->id)
            // The count is what the screen shows against each row and the
            // reason a delete is refused, so the list carries it rather
            // than making the panel ask per role.
            ->withCount('users')
            ->orderBy('id')
            ->get();

        return MerchantRoleResource::collection($roles);
    }

    public function store(Request $request, RoleService $roles): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:80'],
            // `present` rather than `required`: a role holding nothing yet
            // is a legitimate starting point, and an empty array is empty
            // to `required`.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', Rule::in(Permission::values())],
            // Not editable by anyone, through any path. The unbounded role
            // is seeded once per store (RolePresetService); a store that
            // could mint a second would route around every delegation rule
            // in RoleService, and the slug is how the presets are found.
            'is_owner' => ['prohibited'],
            'is_system' => ['prohibited'],
            'slug' => ['prohibited'],
        ]);

        try {
            $role = $roles->create(
                $this->merchant($request),
                $this->actor($request),
                $validated['name'],
                $validated['name_dv'] ?? null,
                array_values($validated['permissions']),
            );
        } catch (RoleException $e) {
            return new JsonResponse($e->payload(), $e->status);
        }

        return (new MerchantRoleResource($role->loadCount('users')))
            ->response($request)
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id, RoleService $roles): JsonResponse
    {
        $role = $this->role($request, $id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:80'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', Rule::in(Permission::values())],
            'is_owner' => ['prohibited'],
            'is_system' => ['prohibited'],
            'slug' => ['prohibited'],
        ]);

        // Only the keys actually sent are passed on — `name_dv` is nullable,
        // so "clear it" and "leave it alone" are different requests that
        // both arrive as null.
        $changes = array_intersect_key($validated, array_flip(['name', 'name_dv', 'permissions']));

        if (array_key_exists('permissions', $changes)) {
            $changes['permissions'] = array_values($changes['permissions']);
        }

        try {
            $role = $roles->update($role, $this->actor($request), $changes);
        } catch (RoleException $e) {
            return new JsonResponse($e->payload(), $e->status);
        }

        return (new MerchantRoleResource($role->loadCount('users')))->response($request);
    }

    public function destroy(Request $request, int $id, RoleService $roles): Response|JsonResponse
    {
        $role = $this->role($request, $id);

        try {
            $roles->delete($role);
        } catch (RoleException $e) {
            return new JsonResponse($e->payload(), $e->status);
        }

        return response()->noContent();
    }

    private function role(Request $request, int $id): MerchantRole
    {
        $role = MerchantRole::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->whereKey($id)
            ->first();

        if ($role === null) {
            abort(404, 'No such role.');
        }

        return $role;
    }

    private function actor(Request $request): MerchantUser
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user;
    }

    private function merchant(Request $request): Merchant
    {
        return $this->actor($request)->merchant;
    }
}
