'use client';

import { ReactNode } from 'react';
import {
  updateAdminUser,
  type AdminUserAccount,
  type UpdateAdminUserRequest,
} from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import type { AdminUser } from '@/lib/admin-auth';
import { apiErrorMessage } from '@/lib/api-error';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * A small action button that, when guarded, renders disabled with the guard
 * reason as a tooltip — the same self/last-superadmin rules the server
 * enforces with 422s, surfaced before the click instead of after.
 */
function GuardedTrigger({
  disabledReason,
  children,
}: {
  disabledReason: string | null;
  children: ReactNode;
}) {
  if (disabledReason === null) {
    return <>{children}</>;
  }
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* span receives hover events the disabled button would swallow */}
        <span tabIndex={0} className="inline-flex">
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent>{disabledReason}</TooltipContent>
    </Tooltip>
  );
}

function ConfirmedAction({
  label,
  destructive = false,
  disabledReason,
  title,
  description,
  confirmLabel,
  pending,
  onConfirm,
}: {
  label: string;
  destructive?: boolean;
  disabledReason: string | null;
  title: string;
  description: ReactNode;
  confirmLabel: string;
  pending: boolean;
  onConfirm: () => void;
}) {
  const disabled = disabledReason !== null || pending;

  return (
    <AlertDialog>
      <GuardedTrigger disabledReason={disabledReason}>
        <AlertDialogTrigger asChild>
          <Button variant="outline" size="sm" disabled={disabled}>
            {label}
          </Button>
        </AlertDialogTrigger>
      </GuardedTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            variant={destructive ? 'destructive' : undefined}
            onClick={onConfirm}
          >
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

/**
 * Role change, deactivate and reactivate for one admin row. The guards
 * mirror AdminAccountService: nobody demotes or deactivates themselves, and
 * the last active superadmin can be neither demoted nor deactivated — shown
 * here as disabled controls with tooltips rather than post-submit errors.
 */
export function AdminRowActions({
  admin,
  me,
  admins,
}: {
  admin: AdminUserAccount;
  me: AdminUser;
  admins: AdminUserAccount[];
}) {
  const queryClient = useQueryClient();

  const update = useMutation({
    mutationFn: ({
      body,
    }: {
      body: UpdateAdminUserRequest;
      successMessage: string;
    }) => updateAdminUser(admin.id, body),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'admin-users'] });
      toast.success(variables.successMessage);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const isSelf = admin.id === me.id;
  const isLastActiveSuperadmin =
    admin.role === 'superadmin' &&
    admin.is_active &&
    !admins.some(
      (other) =>
        other.id !== admin.id && other.role === 'superadmin' && other.is_active,
    );

  const lastSuperadminReason =
    'This is the last active superadmin — losing it would lock admin management permanently.';

  if (!admin.is_active) {
    return (
      <Button
        variant="outline"
        size="sm"
        disabled={update.isPending}
        onClick={() =>
          update.mutate({
            body: { is_active: true },
            successMessage: `${admin.name} reactivated.`,
          })
        }
      >
        {update.isPending ? 'Reactivating…' : 'Reactivate'}
      </Button>
    );
  }

  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      {admin.role === 'superadmin' ? (
        <ConfirmedAction
          label="Demote to admin"
          disabledReason={
            isLastActiveSuperadmin
              ? lastSuperadminReason
              : isSelf
                ? 'You cannot demote your own account.'
                : null
          }
          title={`Demote ${admin.name} to admin?`}
          description="They keep every admin screen but lose admin account management."
          confirmLabel="Demote"
          pending={update.isPending}
          onConfirm={() =>
            update.mutate({
              body: { role: 'admin' },
              successMessage: `${admin.name} is now an admin.`,
            })
          }
        />
      ) : (
        <ConfirmedAction
          label="Promote to superadmin"
          disabledReason={null}
          title={`Promote ${admin.name} to superadmin?`}
          description="They will be able to create, deactivate and change the role of every admin account, including yours."
          confirmLabel="Promote"
          pending={update.isPending}
          onConfirm={() =>
            update.mutate({
              body: { role: 'superadmin' },
              successMessage: `${admin.name} is now a superadmin.`,
            })
          }
        />
      )}
      <ConfirmedAction
        label="Deactivate"
        destructive
        disabledReason={
          isLastActiveSuperadmin
            ? lastSuperadminReason
            : isSelf
              ? 'You cannot deactivate your own account.'
              : null
        }
        title={`Deactivate ${admin.name}?`}
        description="They can no longer sign in. The account stays on record — audit trails that reference it keep resolving — and it can be reactivated at any time."
        confirmLabel="Deactivate"
        pending={update.isPending}
        onConfirm={() =>
          update.mutate({
            body: { is_active: false },
            successMessage: `${admin.name} deactivated.`,
          })
        }
      />
    </div>
  );
}
