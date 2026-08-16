'use client';

import { ReactNode } from 'react';
import type { MerchantPermission } from '@manfaa/api-client';
import { ShieldAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useLayout } from '@/components/app-layout/context';
import { can, permissionLabel } from '@/lib/roles';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';

/**
 * Renders `children` only for an account holding `permission`, and
 * otherwise says which one is missing (PLAN §13b).
 *
 * A courtesy for anyone who deep-links past a hidden nav entry — the API
 * refuses the same request server-side with 403 `permission_required`, so
 * nothing here is a security boundary.
 */
export function RoleGate({
  permission,
  children,
}: {
  permission: MerchantPermission;
  children: ReactNode;
}) {
  const { me } = useLayout();

  if (can(me, permission)) {
    return <>{children}</>;
  }

  return <RoleGateNotice permission={permission} />;
}

/**
 * The refusal, naming the ACT the reader is missing rather than a rank.
 * There are no ranks left to name: a store writes its own roles, so "ask a
 * manager" may be advice about a job nobody at that shop holds, while "you
 * need permission to view settlements" is both true and actionable — the
 * words match the checkbox on the roles screen an owner would go and tick.
 *
 * `permission` is null only for a screen that is a doorway to several
 * others (/settings), where no single permission is the missing one.
 */
export function RoleGateNotice({
  permission,
}: {
  permission?: MerchantPermission | null;
}) {
  const { me } = useLayout();
  const { t } = useTranslation();

  return (
    <div className="container">
      <div className="max-w-lg pt-10">
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <ShieldAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>{t('roles.gate.title')}</AlertTitle>
            <AlertDescription>
              {permission
                ? t('roles.gate.body', {
                    permission: permissionLabel(t, permission),
                    store: me.merchant.name,
                  })
                : t('roles.gate.bodyNone', { store: me.merchant.name })}
            </AlertDescription>
          </AlertContent>
        </Alert>
      </div>
    </div>
  );
}
