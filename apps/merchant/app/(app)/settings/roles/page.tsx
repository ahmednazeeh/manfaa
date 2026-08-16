'use client';

import { useState } from 'react';
import {
  type MerchantPermissionGroup,
  type MerchantRole,
} from '@manfaa/api-client';
import {
  LoaderCircle,
  Lock,
  Pencil,
  Plus,
  Trash2,
  TriangleAlert,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { merchantRoleErrorLabel, roleDisplayName } from '@/lib/labels';
import {
  apiErrorCode,
  apiErrorMessage,
  roleErrorPermissions,
  roleErrorStaffCount,
  useCreateRole,
  useDeleteRole,
  usePermissionCatalogue,
  useRoles,
  useUpdateRole,
} from '@/lib/queries';
import { can, permissionLabel } from '@/lib/roles';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { mayDelete, mayEdit, mayTick, type RoleActor } from './delegation';

/**
 * Settings › Roles (PLAN §13b staff permissions) — the store decides what
 * each job in the shop may do, and the catalogue of things it can choose
 * from is SERVED rather than compiled in (D8), so a permission added by a
 * later API deploy appears here, under its own heading and in its own
 * words, without a panel build.
 *
 * Three rules the screen has to make visible rather than merely obey:
 *
 *  1. THE OWNER ROLE IS FROZEN (D9). Renameable, never editable, never
 *     deletable — because a role that could be stripped of staff management
 *     is how a store locks itself out of its own account. The screen says
 *     that in one line rather than greying out a control with no reason.
 *  2. YOU MAY ONLY DELEGATE WHAT YOU HOLD (D5). A permission the reader
 *     lacks is drawn disabled with a reason, never as a box that ticks and
 *     then 403s.
 *  3. A ROLE IN USE CANNOT BE DELETED, and the refusal names how many
 *     accounts stand on it — "move them first" is only actionable if the
 *     shopkeeper knows how many there are.
 */

/** Mirrors RoleService::MAX_PER_MERCHANT. Display only; the server refuses. */
const ROLE_CAP = 20;

interface RoleFormValues {
  name: string;
  nameDv: string;
  permissions: string[];
}

/**
 * The checkbox grid, stacked by the catalogue's own groups. Nothing here
 * knows what any group or permission IS — the labels and the grouping both
 * arrive on the wire, which is the entire point of publishing the
 * catalogue.
 */
function PermissionPicker({
  groups,
  selected,
  actor,
  stored,
  frozen,
  onToggle,
  onToggleGroup,
}: {
  groups: MerchantPermissionGroup[];
  selected: Set<string>;
  actor: RoleActor;
  /** The role's permissions AS STORED — the edit rule's baseline (D5). */
  stored: Set<string>;
  frozen: boolean;
  onToggle: (slug: string, checked: boolean) => void;
  onToggleGroup: (slugs: string[], checked: boolean) => void;
}) {
  const { t } = useTranslation();

  const locked = (slug: string) =>
    frozen || !mayTick(actor, slug, stored.has(slug));

  const anyLocked =
    !frozen &&
    groups.some((group) =>
      group.permissions.some((permission) => locked(permission.slug)),
    );

  return (
    <div className="flex flex-col gap-5">
      {frozen ? (
        <Alert variant="info" appearance="light">
          <AlertIcon>
            <Lock />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>{t('roles.ownerFrozenTitle')}</AlertTitle>
            <AlertDescription>{t('roles.ownerFrozenWhy')}</AlertDescription>
          </AlertContent>
        </Alert>
      ) : (
        anyLocked && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertDescription>{t('roles.notHeldNotice')}</AlertDescription>
            </AlertContent>
          </Alert>
        )
      )}

      {groups.map((group) => {
        const tickable = group.permissions
          .map((permission) => permission.slug)
          .filter((slug) => !locked(slug));
        const allTicked =
          tickable.length > 0 && tickable.every((slug) => selected.has(slug));

        return (
          <div key={group.slug} className="flex flex-col gap-2.5">
            <div className="flex items-center justify-between gap-3">
              <span className="text-sm font-medium">{group.label}</span>
              {tickable.length > 0 && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onToggleGroup(tickable, !allTicked)}
                >
                  {allTicked ? t('roles.clearGroup') : t('roles.selectGroup')}
                </Button>
              )}
            </div>

            <div className="flex flex-col gap-2 rounded-md border border-border p-3">
              {group.permissions.map((permission) => {
                const disabled = locked(permission.slug);

                return (
                  <label
                    key={permission.slug}
                    className={`flex items-start gap-2.5 text-sm ${
                      disabled
                        ? 'cursor-not-allowed opacity-70'
                        : 'cursor-pointer'
                    }`}
                  >
                    <Checkbox
                      className="mt-0.5"
                      checked={selected.has(permission.slug)}
                      disabled={disabled}
                      onCheckedChange={(checked) =>
                        onToggle(permission.slug, checked === true)
                      }
                    />
                    <span className="flex flex-col gap-0.5">
                      <span>{permission.label}</span>
                      {disabled && !frozen && (
                        <span className="text-xs text-muted-foreground">
                          {t('roles.notHeldTag')}
                        </span>
                      )}
                    </span>
                  </label>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function RoleDialog({
  open,
  title,
  submitLabel,
  role,
  actor,
  busy,
  serverError,
  onOpenChange,
  onSubmit,
}: {
  open: boolean;
  title: string;
  submitLabel: string;
  /** Null when creating. */
  role: MerchantRole | null;
  actor: RoleActor;
  busy: boolean;
  serverError: string | null;
  onOpenChange: (open: boolean) => void;
  onSubmit: (values: RoleFormValues) => void;
}) {
  const { t } = useTranslation();
  const catalogue = usePermissionCatalogue();

  // The parent remounts this dialog on open (via `key`), so state seeded
  // from the role is fresh for whichever row was clicked.
  const [name, setName] = useState(role?.name ?? '');
  const [nameDv, setNameDv] = useState(role?.name_dv ?? '');
  const [selected, setSelected] = useState<Set<string>>(
    new Set(role?.permissions ?? []),
  );

  const frozen = role?.is_owner === true;
  const stored = new Set(role?.permissions ?? []);
  const trimmedName = name.trim();
  const canSubmit = trimmedName.length >= 2 && !busy;

  const toggle = (slug: string, checked: boolean) => {
    setSelected((current) => {
      const next = new Set(current);
      if (checked) {
        next.add(slug);
      } else {
        next.delete(slug);
      }
      return next;
    });
  };

  const toggleGroup = (slugs: string[], checked: boolean) => {
    setSelected((current) => {
      const next = new Set(current);
      for (const slug of slugs) {
        if (checked) {
          next.add(slug);
        } else {
          next.delete(slug);
        }
      }
      return next;
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex max-h-[65vh] flex-col gap-5 overflow-y-auto">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="role-name">{t('roles.nameLabel')}</Label>
            <Input
              id="role-name"
              value={name}
              maxLength={80}
              placeholder={t('roles.namePlaceholder')}
              onChange={(event) => setName(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              {t('roles.nameHint')}
            </p>
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="role-name-dv">{t('roles.nameDvLabel')}</Label>
            <Input
              id="role-name-dv"
              dir="rtl"
              lang="dv"
              value={nameDv}
              maxLength={80}
              onChange={(event) => setNameDv(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              {t('roles.nameDvHint')}
            </p>
          </div>

          <div className="flex flex-col gap-2.5">
            <Label>{t('roles.permissionsLabel')}</Label>
            {catalogue.error ? (
              <ErrorBlock
                error={catalogue.error}
                fallback={t('roles.catalogueFailed')}
              />
            ) : !catalogue.data ? (
              <LoadingBlock lines={5} />
            ) : (
              <PermissionPicker
                groups={catalogue.data}
                selected={selected}
                actor={actor}
                stored={stored}
                frozen={frozen}
                onToggle={toggle}
                onToggleGroup={toggleGroup}
              />
            )}
          </div>

          {serverError !== null && (
            <p className="text-sm text-destructive">{serverError}</p>
          )}
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={!canSubmit}
            onClick={() =>
              onSubmit({
                name: trimmedName,
                nameDv: nameDv.trim(),
                permissions: Array.from(selected),
              })
            }
          >
            {busy && <LoaderCircle className="animate-spin" />}
            {submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * What a role lets its holders do, at a glance: one badge per group with a
 * permission in it. The owner role is the wildcard, so it says so instead
 * of counting — its stored set is empty and its resolved one is whatever
 * the catalogue holds today.
 *
 * A slug no group describes still gets counted rather than dropped: that is
 * either a panel older than the API, or a catalogue that failed to load,
 * and silently showing a smaller number would be worse than a plain count.
 */
function PermissionSummary({
  role,
  groups,
}: {
  role: MerchantRole;
  groups: MerchantPermissionGroup[];
}) {
  const { t } = useTranslation();

  if (role.is_owner) {
    return (
      <Badge variant="primary" appearance="light" size="sm">
        {t('roles.everything')}
      </Badge>
    );
  }

  if (role.permissions.length === 0) {
    return (
      <span className="text-xs text-muted-foreground">
        {t('roles.noPermissions')}
      </span>
    );
  }

  const held = new Set(role.permissions);
  const described = new Set(
    groups.flatMap((group) =>
      group.permissions.map((permission) => permission.slug),
    ),
  );
  const counted = groups
    .map((group) => ({
      slug: group.slug,
      label: group.label,
      count: group.permissions.filter((permission) => held.has(permission.slug))
        .length,
    }))
    .filter((group) => group.count > 0);
  const undescribed = role.permissions.filter(
    (slug) => !described.has(slug),
  ).length;

  return (
    <div className="flex flex-wrap gap-1.5">
      {counted.map((group) => (
        <Badge
          key={group.slug}
          variant="secondary"
          appearance="light"
          size="sm"
        >
          {group.label} · {group.count}
        </Badge>
      ))}
      {undescribed > 0 && (
        <Badge variant="secondary" appearance="light" size="sm">
          {t('roles.permissionCount', { count: undescribed })}
        </Badge>
      )}
    </div>
  );
}

export default function RolesSettingsPage() {
  const { t, i18n } = useTranslation();
  const { me } = useLayout();
  const roles = useRoles();
  const catalogue = usePermissionCatalogue();
  const createRole = useCreateRole();
  const updateRole = useUpdateRole();
  const deleteRole = useDeleteRole();

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<MerchantRole | null>(null);
  const [deleting, setDeleting] = useState<MerchantRole | null>(null);
  const [dialogError, setDialogError] = useState<string | null>(null);

  const actor: RoleActor = { permissions: me.permissions, role: me.role };
  const canManage = can(actor, 'roles.manage');
  const rows = roles.data ?? [];
  const groups = catalogue.data ?? [];
  const atCap = rows.length >= ROLE_CAP;

  /**
   * A refused write in the reader's language. The coded refusals each need
   * a different repair, and `permission_not_held` additionally names the
   * offending permissions — in the catalogue's own words, so the sentence
   * points at boxes the shopkeeper can see rather than at slugs.
   */
  const failureMessage = (error: unknown, fallback: string): string => {
    const code = apiErrorCode(error);

    if (code === 'permission_not_held') {
      const labels = roleErrorPermissions(error).map((slug) => {
        // The served catalogue is the wording the checkboxes carry, so the
        // sentence points at what the reader can see. The panel's own
        // translation is the fallback, and it degrades to neutral prose —
        // the slug itself is never the rendered string.
        const entry = groups
          .flatMap((group) => group.permissions)
          .find((permission) => permission.slug === slug);
        return entry?.label ?? permissionLabel(t, slug);
      });
      return labels.length === 0
        ? merchantRoleErrorLabel(t, 'permission_not_held')
        : `${merchantRoleErrorLabel(t, 'permission_not_held')} ${t(
            'roles.errors.permissionList',
            { list: labels.join(', ') },
          )}`;
    }

    switch (code) {
      case 'owner_role_not_delegable':
      case 'cannot_edit_own_role':
      case 'owner_role_frozen':
      case 'owner_role_undeletable':
        return merchantRoleErrorLabel(t, code);
      case 'role_cap_reached':
        return merchantRoleErrorLabel(t, code, { cap: ROLE_CAP });
      case 'role_in_use': {
        // Reachable even though the button is disabled on the count: the
        // list is a snapshot, and somebody else may have moved an account
        // onto this role since it loaded. The refusal carries the fresh
        // number, so the sentence names it rather than the stale one.
        const count = roleErrorStaffCount(error);
        return count === null
          ? merchantRoleErrorLabel(t, code)
          : t('roles.deleteBlocked', { count });
      }
      default:
        return apiErrorMessage(error, fallback);
    }
  };

  const handleCreate = (values: RoleFormValues) => {
    setDialogError(null);
    createRole.mutate(
      {
        name: values.name,
        name_dv: values.nameDv === '' ? null : values.nameDv,
        permissions: values.permissions,
      },
      {
        onSuccess: (response) => {
          toast.success(t('roles.created', { name: response.data.name }));
          setCreating(false);
        },
        onError: (error) =>
          setDialogError(failureMessage(error, t('roles.createFailed'))),
      },
    );
  };

  const handleEdit = (values: RoleFormValues) => {
    if (editing === null) return;
    setDialogError(null);
    updateRole.mutate(
      {
        id: editing.id,
        body: {
          name: values.name,
          name_dv: values.nameDv === '' ? null : values.nameDv,
          // The owner role is renameable and nothing else (D9) — sending
          // its permissions at all is refused, so the key is omitted
          // rather than sent unchanged.
          ...(editing.is_owner ? {} : { permissions: values.permissions }),
        },
      },
      {
        onSuccess: () => {
          toast.success(t('roles.saved'));
          setEditing(null);
        },
        onError: (error) =>
          setDialogError(failureMessage(error, t('roles.saveFailed'))),
      },
    );
  };

  const handleDelete = (role: MerchantRole) => {
    deleteRole.mutate(role.id, {
      onSuccess: () => {
        toast.success(t('roles.deleted', { name: role.name }));
        setDeleting(null);
      },
      onError: (error) =>
        toast.error(failureMessage(error, t('roles.deleteFailed'))),
    });
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('roles.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('roles.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
        {canManage && (
          <ToolbarActions>
            <Button
              disabled={atCap}
              onClick={() => {
                setDialogError(null);
                setCreating(true);
              }}
            >
              <Plus />
              {t('roles.newRole')}
            </Button>
          </ToolbarActions>
        )}
      </Toolbar>

      {atCap && canManage && (
        <Alert variant="warning" appearance="light" className="mb-5">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>{t('roles.capTitle', { cap: ROLE_CAP })}</AlertTitle>
            <AlertDescription>{t('roles.capBody')}</AlertDescription>
          </AlertContent>
        </Alert>
      )}

      {!canManage && (
        <Alert variant="info" appearance="light" className="mb-5">
          <AlertContent>
            <AlertDescription>{t('roles.readOnly')}</AlertDescription>
          </AlertContent>
        </Alert>
      )}

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {roles.data
              ? t('roles.count', { count: rows.length, cap: ROLE_CAP })
              : t('roles.title')}
          </CardTitle>
        </CardHeader>

        {roles.error ? (
          <ErrorBlock error={roles.error} fallback={t('roles.loadFailed')} />
        ) : !roles.data ? (
          <LoadingBlock lines={4} />
        ) : rows.length === 0 ? (
          <EmptyBlock>{t('roles.empty')}</EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('roles.columnRole')}</TableHead>
                    <TableHead>{t('roles.columnPermissions')}</TableHead>
                    <TableHead>{t('roles.columnStaff')}</TableHead>
                    <TableHead className="w-40" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((role) => {
                    const editable = mayEdit(actor, role);
                    const deletable = mayDelete(actor, role);

                    return (
                      <TableRow key={role.id}>
                        <TableCell>
                          <div className="font-medium">
                            {roleDisplayName(role, i18n.language)}
                          </div>
                          <div className="flex flex-wrap gap-1.5 mt-1">
                            {role.is_owner && (
                              <Badge
                                variant="primary"
                                appearance="light"
                                size="sm"
                              >
                                {t('roles.ownerBadge')}
                              </Badge>
                            )}
                            {role.is_system && !role.is_owner && (
                              <Badge
                                variant="secondary"
                                appearance="light"
                                size="sm"
                              >
                                {t('roles.presetBadge')}
                              </Badge>
                            )}
                          </div>
                          {role.is_owner && (
                            <div className="text-xs text-muted-foreground mt-1.5">
                              {t('roles.ownerFrozenWhy')}
                            </div>
                          )}
                        </TableCell>
                        <TableCell>
                          <PermissionSummary role={role} groups={groups} />
                        </TableCell>
                        <TableCell className="text-sm">
                          {t('roles.staffCount', { count: role.staff_count })}
                        </TableCell>
                        <TableCell>
                          {canManage && (
                            <div className="flex flex-col items-start gap-1.5">
                              <div className="flex items-center gap-2">
                                <Button
                                  variant="outline"
                                  size="sm"
                                  disabled={!editable}
                                  aria-label={t('roles.editAria', {
                                    name: roleDisplayName(role, i18n.language),
                                  })}
                                  onClick={() => {
                                    setDialogError(null);
                                    setEditing(role);
                                  }}
                                >
                                  <Pencil />
                                  {t('roles.edit')}
                                </Button>
                                {!role.is_owner && (
                                  <Button
                                    variant="ghost"
                                    mode="icon"
                                    size="sm"
                                    disabled={!deletable}
                                    aria-label={t('roles.deleteAria', {
                                      name: roleDisplayName(
                                        role,
                                        i18n.language,
                                      ),
                                    })}
                                    onClick={() => setDeleting(role)}
                                  >
                                    <Trash2 />
                                  </Button>
                                )}
                              </div>
                              {/* Every disabled control says why: a greyed
                                  button with no sentence is the thing this
                                  screen is meant to stop. */}
                              {!editable && (
                                <span className="text-xs text-muted-foreground">
                                  {t('roles.cannotEditOwn')}
                                </span>
                              )}
                              {!role.is_owner && role.staff_count > 0 && (
                                <span className="text-xs text-muted-foreground">
                                  {t('roles.deleteBlocked', {
                                    count: role.staff_count,
                                  })}
                                </span>
                              )}
                            </div>
                          )}
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
        <RoleDialog
          key="role-create"
          open
          title={t('roles.createTitle')}
          submitLabel={t('roles.createSubmit')}
          role={null}
          actor={actor}
          busy={createRole.isPending}
          serverError={dialogError}
          onOpenChange={(open) => {
            if (!open) setCreating(false);
          }}
          onSubmit={handleCreate}
        />
      )}

      {editing !== null && (
        <RoleDialog
          key={`role-edit-${editing.id}`}
          open
          title={t('roles.editTitle', {
            name: roleDisplayName(editing, i18n.language),
          })}
          submitLabel={t('common.save')}
          role={editing}
          actor={actor}
          busy={updateRole.isPending}
          serverError={dialogError}
          onOpenChange={(open) => {
            if (!open) setEditing(null);
          }}
          onSubmit={handleEdit}
        />
      )}

      <AlertDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('roles.deleteTitle', {
                name:
                  deleting === null
                    ? ''
                    : roleDisplayName(deleting, i18n.language),
              })}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {deleting !== null && deleting.staff_count > 0
                ? t('roles.deleteBlocked', { count: deleting.staff_count })
                : t('roles.deleteBody')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              disabled={
                deleting === null ||
                deleting.staff_count > 0 ||
                deleteRole.isPending
              }
              onClick={() => deleting !== null && handleDelete(deleting)}
            >
              {t('roles.deleteConfirm')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
