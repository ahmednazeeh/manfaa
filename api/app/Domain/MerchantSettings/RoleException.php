<?php

declare(strict_types=1);

namespace App\Domain\MerchantSettings;

use App\Domain\MerchantAccess\Permission;
use DomainException;

/**
 * A refused change to a merchant's roles. Every refusal carries a
 * machine-readable code, because the roles screen has to say WHICH rule bit
 * — "you cannot hand out a permission you do not hold yourself" and "this
 * role is still assigned to three people" need different repairs, and a
 * panel that can only print prose can offer neither.
 *
 * The status travels with the code for the same reason it does everywhere
 * else in this API: the delegation refusals are authorisation (403, the
 * same family as `permission_required`), while the frozen owner role, a
 * role still in use and the per-merchant cap are states of the resource
 * itself (409 / 422, as `branch_referenced` and `credential_cap_reached`
 * already are).
 */
final class RoleException extends DomainException
{
    public const string CODE_OWNER_FROZEN = 'owner_role_frozen';

    public const string CODE_OWNER_UNDELETABLE = 'owner_role_undeletable';

    public const string CODE_OWNER_NOT_DELEGABLE = 'owner_role_not_delegable';

    public const string CODE_PERMISSION_NOT_HELD = 'permission_not_held';

    public const string CODE_OWN_ROLE = 'cannot_edit_own_role';

    public const string CODE_IN_USE = 'role_in_use';

    public const string CODE_CAP_REACHED = 'role_cap_reached';

    /**
     * @param  array<string, mixed>  $context  extra body fields — the slugs
     *                                         a refusal names, so the panel
     *                                         can point at the checkbox
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $context,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function ownerPermissionsFrozen(): self
    {
        return new self(
            self::CODE_OWNER_FROZEN,
            409,
            [],
            'The owner role always holds every permission, so its permissions cannot be edited. It can still be renamed.',
        );
    }

    public static function ownerRoleUndeletable(): self
    {
        return new self(
            self::CODE_OWNER_UNDELETABLE,
            409,
            [],
            'The owner role cannot be deleted — every store keeps one.',
        );
    }

    public static function ownerRoleNotDelegable(): self
    {
        return new self(
            self::CODE_OWNER_NOT_DELEGABLE,
            403,
            [],
            'Only an owner can hand out the owner role.',
        );
    }

    /**
     * @param  list<Permission>  $missing
     */
    public static function permissionsNotHeld(array $missing): self
    {
        $slugs = array_map(fn (Permission $permission) => $permission->value, $missing);

        return new self(
            self::CODE_PERMISSION_NOT_HELD,
            403,
            ['permissions' => $slugs],
            sprintf(
                'You cannot give a role a permission you do not hold yourself: %s.',
                implode(', ', array_map(
                    fn (Permission $permission) => lcfirst($permission->label()),
                    $missing,
                )),
            ),
        );
    }

    public static function cannotEditOwnRole(): self
    {
        return new self(
            self::CODE_OWN_ROLE,
            403,
            [],
            'You cannot edit the role you hold yourself. Ask an owner to change it.',
        );
    }

    public static function inUse(int $staffCount): self
    {
        return new self(
            self::CODE_IN_USE,
            409,
            ['staff_count' => $staffCount],
            sprintf(
                'This role is assigned to %d staff %s. Move them to another role first.',
                $staffCount,
                $staffCount === 1 ? 'account' : 'accounts',
            ),
        );
    }

    public static function capReached(int $cap): self
    {
        return new self(
            self::CODE_CAP_REACHED,
            422,
            [],
            sprintf('A store can have at most %d roles. Delete one you no longer use.', $cap),
        );
    }

    /**
     * The refusal body, in one place — same shape as every other coded
     * refusal the merchant panel already handles.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->context;
    }
}
