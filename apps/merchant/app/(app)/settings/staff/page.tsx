'use client';

import { useState } from 'react';
import {
  type CreateMerchantStaffResponse,
  type MerchantRole,
  type MerchantStaff,
} from '@manfaa/api-client';
import { Copy, KeyRound, LoaderCircle, Plus, UserRound } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { roleDisplayName } from '@/lib/labels';
import {
  apiErrorMessage,
  useCreateStaff,
  useRoles,
  useStaff,
  useUpdateStaff,
} from '@/lib/queries';
import { can } from '@/lib/roles';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTable, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import { mayAssign, type RoleActor } from '../roles/delegation';

/**
 * Staff management. There is deliberately no delete — deactivation is the
 * only removal, so the audit trail keeps its actors.
 *
 * A role is one of the STORE'S OWN roles now (PLAN §13b), which changes
 * three things on this screen:
 *
 *  1. The picker is the merchant's own list, and a role is editable long
 *     after the invite — the shop that promotes a cashier on a Tuesday
 *     should not have to delete and re-invite the account.
 *  2. Handing someone a role is a grant, so the same D5 rule the roles
 *     screen obeys applies here: a role holding a permission the reader
 *     lacks is offered disabled, never as an option that 403s.
 *  3. The last-owner guard keys on the role's `is_owner` FLAG (D4), not on
 *     any permission — so a custom role holding `staff.edit` is not a way
 *     to leave the store with nobody who can reach its bank account.
 *
 * Every guard here is a courtesy: the API refuses each one independently
 * (422 for the last owner and for self-targeting, a coded 403 for
 * delegation). What the screen adds is the SENTENCE — a disabled control
 * with no explanation is the thing a shopkeeper mails support about.
 */

/** The picker's rows, with the delegation rule applied to each. */
function RoleOptions({
  roles,
  actor,
}: {
  roles: MerchantRole[];
  actor: RoleActor;
}) {
  const { i18n } = useTranslation();

  return (
    <>
      {roles.map((role) => (
        <SelectItem
          key={role.id}
          value={String(role.id)}
          disabled={!mayAssign(actor, role)}
        >
          {roleDisplayName(role, i18n.language)}
        </SelectItem>
      ))}
    </>
  );
}

function CreateStaffDialog({
  open,
  roles,
  actor,
  onOpenChange,
  onCreated,
}: {
  open: boolean;
  roles: MerchantRole[];
  actor: RoleActor;
  onOpenChange: (open: boolean) => void;
  onCreated: (response: CreateMerchantStaffResponse) => void;
}) {
  const { t } = useTranslation();
  const createStaff = useCreateStaff();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  // No default: with per-store roles there is no tier left to fall back to,
  // and a preselected role is authority nobody chose.
  const [roleId, setRoleId] = useState<number | null>(null);

  const anyLocked = roles.some((role) => !mayAssign(actor, role));
  const canSubmit =
    name.trim() !== '' &&
    email.trim() !== '' &&
    roleId !== null &&
    !createStaff.isPending;

  const submit = () => {
    if (roleId === null) return;
    createStaff.mutate(
      { name: name.trim(), email: email.trim(), merchant_role_id: roleId },
      {
        onSuccess: (response) => {
          onCreated(response);
        },
        onError: (error) =>
          toast.error(apiErrorMessage(error, t('staff.createFailed'))),
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t('staff.newTitle')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="staff-name">{t('staff.nameLabel')}</Label>
            <Input
              id="staff-name"
              value={name}
              maxLength={255}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="staff-email">{t('staff.emailLabel')}</Label>
            <Input
              id="staff-email"
              type="email"
              dir="ltr"
              value={email}
              maxLength={255}
              onChange={(event) => setEmail(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              {t('staff.emailHint')}
            </p>
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="staff-role">{t('roles.pickerLabel')}</Label>
            <Select
              value={roleId === null ? undefined : String(roleId)}
              onValueChange={(value) => setRoleId(Number(value))}
            >
              <SelectTrigger id="staff-role">
                <SelectValue placeholder={t('staff.rolePlaceholder')} />
              </SelectTrigger>
              <SelectContent>
                <RoleOptions roles={roles} actor={actor} />
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {t('roles.inviteHint')}
              {anyLocked && ` ${t('staff.notAssignableHint')}`}
            </p>
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={!canSubmit} onClick={submit}>
            {createStaff.isPending ? (
              <LoaderCircle className="animate-spin" />
            ) : (
              <UserRound />
            )}
            {t('staff.create')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * The one-time password handover. The password exists only in this response
 * — only its hash survives server-side — so the dialog cannot be dismissed
 * until the owner confirms it has been passed on.
 */
function TempPasswordDialog({
  created,
  onDone,
}: {
  created: CreateMerchantStaffResponse;
  onDone: () => void;
}) {
  const { t } = useTranslation();
  const [acknowledged, setAcknowledged] = useState(false);
  const { copyToClipboard } = useCopyToClipboard();

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        // Locked until acknowledged: ignore escape/overlay closes.
        if (!open && acknowledged) onDone();
      }}
    >
      <DialogContent className="max-w-md" showCloseButton={false}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <KeyRound className="size-4.5" />
            {t('staff.tempTitle', { name: created.data.name })}
          </DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant="warning" appearance="light">
            <AlertContent>
              <AlertTitle>{t('staff.tempOnceTitle')}</AlertTitle>
              <AlertDescription>
                {t('staff.tempOnceBody', { name: created.data.name })}
              </AlertDescription>
            </AlertContent>
          </Alert>

          <div className="flex flex-col gap-1.5 text-sm">
            <span className="text-muted-foreground">
              {t('staff.loginEmail')}
            </span>
            <span dir="ltr" className="text-mono">
              {created.data.email}
            </span>
          </div>

          <div className="flex items-center gap-2">
            <code
              dir="ltr"
              className="grow rounded-md border border-border bg-muted px-3 py-2 text-sm text-mono tracking-wide"
            >
              {created.temp_password}
            </code>
            <Button
              variant="outline"
              mode="icon"
              aria-label={t('staff.copyPassword')}
              onClick={() => {
                copyToClipboard(created.temp_password);
                toast.success(t('staff.passwordCopied'));
              }}
            >
              <Copy />
            </Button>
          </div>

          <label className="flex items-start gap-2.5 text-sm cursor-pointer">
            <Checkbox
              checked={acknowledged}
              onCheckedChange={(checked) => setAcknowledged(checked === true)}
              className="mt-0.5"
            />
            <span>{t('staff.tempAcknowledge')}</span>
          </label>
        </DialogBody>
        <DialogFooter>
          <Button disabled={!acknowledged} onClick={onDone}>
            {t('staff.done')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function StaffSettingsPage() {
  const { t, i18n } = useTranslation();
  const { me } = useLayout();
  const staff = useStaff();
  const updateStaff = useUpdateStaff();

  const actor: RoleActor = { permissions: me.permissions, role: me.role };
  const canInvite = can(actor, 'staff.invite');
  const canEdit = can(actor, 'staff.edit');
  /**
   * The role picker needs the store's roles, and reading them is its own
   * permission. A custom role holding `staff.edit` but not `roles.view`
   * therefore has nothing to choose from — the screen says so rather than
   * showing an empty picker.
   */
  const canSeeRoles = can(actor, 'roles.view');
  const roles = useRoles(canSeeRoles);

  const [creating, setCreating] = useState(false);
  const [created, setCreated] = useState<CreateMerchantStaffResponse | null>(
    null,
  );

  const roleRows = roles.data ?? [];
  const pickerReady = canSeeRoles && roleRows.length > 0;
  /**
   * The last-owner guard's own arithmetic (D4): accounts standing on an
   * OWNER-FLAGGED role, active. A role that merely holds wide permissions
   * deliberately does not count — only the flag carries the standing the
   * guard protects.
   */
  const activeOwners =
    staff.data?.filter(
      (user) => user.role?.is_owner === true && user.is_active,
    ) ?? [];

  const mutate = (
    user: MerchantStaff,
    body: { merchant_role_id?: number; is_active?: boolean },
    success: string,
  ) => {
    updateStaff.mutate(
      { id: user.id, body },
      {
        onSuccess: () => toast.success(success),
        onError: (error) =>
          toast.error(apiErrorMessage(error, t('staff.updateFailed'))),
      },
    );
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('staff.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('staff.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
        {canInvite && (
          <ToolbarActions>
            <Button disabled={!pickerReady} onClick={() => setCreating(true)}>
              <Plus />
              {t('staff.add')}
            </Button>
          </ToolbarActions>
        )}
      </Toolbar>

      {!canSeeRoles && (canInvite || canEdit) && (
        <Alert variant="warning" appearance="light" className="mb-5">
          <AlertContent>
            <AlertDescription>{t('staff.needsRolesView')}</AlertDescription>
          </AlertContent>
        </Alert>
      )}

      {canSeeRoles && roles.error !== null && (
        <Alert variant="warning" appearance="light" className="mb-5">
          <AlertContent>
            <AlertDescription>{t('staff.rolesLoadFailed')}</AlertDescription>
          </AlertContent>
        </Alert>
      )}

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {staff.data
              ? t('staff.count', { count: staff.data.length })
              : t('staff.title')}
          </CardTitle>
        </CardHeader>

        {staff.error ? (
          <ErrorBlock error={staff.error} fallback={t('staff.loadFailed')} />
        ) : !staff.data ? (
          <LoadingBlock lines={4} />
        ) : staff.data.length === 0 ? (
          <EmptyBlock>{t('staff.empty')}</EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('staff.columnName')}</TableHead>
                    <TableHead className="w-72">
                      {t('staff.columnRole')}
                    </TableHead>
                    <TableHead className="w-56">
                      {t('staff.columnStatus')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {staff.data.map((user) => {
                    const isSelf = user.id === me.id;
                    const isOwnerAccount = user.role?.is_owner === true;
                    const isLastActiveOwner =
                      isOwnerAccount &&
                      user.is_active &&
                      activeOwners.length === 1;

                    /**
                     * Moving off an owner-flagged role is a demotion however
                     * wide the new role is, so an owner cannot do it to
                     * themselves; everyone else may be reassigned. The two
                     * reasons are ordered by which one the server would
                     * answer with first.
                     */
                    const roleLockedReason = !canEdit
                      ? t('staff.needsStaffEdit')
                      : !pickerReady
                        ? null
                        : isLastActiveOwner
                          ? t('staff.lastOwnerLocked')
                          : isSelf && isOwnerAccount
                            ? t('staff.selfDemoteLocked')
                            : null;
                    const activeLockedReason = !canEdit
                      ? t('staff.needsStaffEdit')
                      : isLastActiveOwner
                        ? t('staff.lastOwnerLocked')
                        : isSelf
                          ? t('staff.selfActiveLocked')
                          : null;

                    const roleDisabled =
                      !canEdit ||
                      roleLockedReason !== null ||
                      updateStaff.isPending;

                    return (
                      <TableRow key={user.id}>
                        <TableCell>
                          <div className="font-medium">
                            {user.name}
                            {isSelf && (
                              <span className="ms-1.5 text-xs font-normal text-muted-foreground">
                                {t('staff.you')}
                              </span>
                            )}
                          </div>
                          <div
                            dir="ltr"
                            className="text-xs text-muted-foreground text-start"
                          >
                            {user.email}
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-col items-start gap-1.5">
                            {/* The picker is the store's own roles, and it
                                stays live after the invite — reassigning is
                                the whole point. Without the list there is
                                nothing to pick FROM, so the role is printed
                                rather than drawn as an empty control. */}
                            {pickerReady ? (
                              <Select
                                value={
                                  user.role === null
                                    ? undefined
                                    : String(user.role.id)
                                }
                                disabled={roleDisabled}
                                onValueChange={(value) => {
                                  const role = roleRows.find(
                                    (candidate) =>
                                      candidate.id === Number(value),
                                  );
                                  if (role === undefined) return;
                                  mutate(
                                    user,
                                    { merchant_role_id: role.id },
                                    t('roles.changed', {
                                      name: user.name,
                                      role: roleDisplayName(
                                        role,
                                        i18n.language,
                                      ),
                                    }),
                                  );
                                }}
                              >
                                <SelectTrigger
                                  className="w-56"
                                  size="sm"
                                  aria-label={t('staff.roleAria', {
                                    name: user.name,
                                  })}
                                >
                                  <SelectValue
                                    placeholder={t('staff.noRole')}
                                  />
                                </SelectTrigger>
                                <SelectContent>
                                  <RoleOptions roles={roleRows} actor={actor} />
                                </SelectContent>
                              </Select>
                            ) : (
                              <span className="text-sm">
                                {user.role === null
                                  ? t('staff.noRole')
                                  : roleDisplayName(user.role, i18n.language)}
                              </span>
                            )}
                            {isOwnerAccount && (
                              <Badge
                                variant="primary"
                                appearance="light"
                                size="sm"
                              >
                                {t('roles.ownerBadge')}
                              </Badge>
                            )}
                            {roleLockedReason !== null && (
                              <span className="text-xs text-muted-foreground">
                                {roleLockedReason}
                              </span>
                            )}
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-col items-start gap-1.5">
                            <div className="flex items-center gap-2.5">
                              <Switch
                                size="sm"
                                checked={user.is_active}
                                disabled={
                                  activeLockedReason !== null ||
                                  updateStaff.isPending
                                }
                                aria-label={t(
                                  user.is_active
                                    ? 'staff.deactivateAria'
                                    : 'staff.activateAria',
                                  { name: user.name },
                                )}
                                onCheckedChange={(checked) =>
                                  mutate(
                                    user,
                                    { is_active: checked },
                                    t(
                                      checked
                                        ? 'staff.activated'
                                        : 'staff.deactivated',
                                      { name: user.name },
                                    ),
                                  )
                                }
                              />
                              <Badge
                                variant={
                                  user.is_active ? 'success' : 'secondary'
                                }
                                appearance="light"
                                size="sm"
                              >
                                {t(
                                  user.is_active
                                    ? 'staff.active'
                                    : 'staff.inactive',
                                )}
                              </Badge>
                            </div>
                            {activeLockedReason !== null && (
                              <span className="text-xs text-muted-foreground">
                                {activeLockedReason}
                              </span>
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        )}
      </Card>

      {creating && (
        <CreateStaffDialog
          key="staff-create"
          open
          roles={roleRows}
          actor={actor}
          onOpenChange={(open) => {
            if (!open) setCreating(false);
          }}
          onCreated={(response) => {
            setCreating(false);
            setCreated(response);
          }}
        />
      )}

      {created && (
        <TempPasswordDialog created={created} onDone={() => setCreated(null)} />
      )}
    </div>
  );
}
