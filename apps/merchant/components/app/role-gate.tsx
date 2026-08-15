'use client';

import { ReactNode } from 'react';
import type { MerchantStaffRole } from '@manfaa/api-client';
import { ShieldAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useLayout } from '@/components/app-layout/context';
import { hasRoleAtLeast } from '@/lib/roles';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';

/**
 * The panel-side half of the three-tier split (PLAN §1): renders `children`
 * only for accounts at or above `min`, and otherwise explains which tier
 * the screen belongs to.
 *
 * A courtesy for anyone who deep-links past a hidden nav entry — the API
 * refuses the same requests server-side with 403 `owner_required` /
 * `manager_required`, so nothing here is a security boundary.
 */
export function RoleGate({
  min,
  children,
}: {
  min: MerchantStaffRole;
  children: ReactNode;
}) {
  const { me } = useLayout();

  if (hasRoleAtLeast(me.role, min)) {
    return <>{children}</>;
  }

  return <RoleGateNotice required={min} />;
}

export function RoleGateNotice({ required }: { required: MerchantStaffRole }) {
  const { me } = useLayout();
  const { t } = useTranslation();
  const owner = required === 'owner';

  return (
    <div className="container">
      <div className="max-w-lg pt-10">
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <ShieldAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>
              {t(owner ? 'roles.gate.ownerTitle' : 'roles.gate.managerTitle')}
            </AlertTitle>
            <AlertDescription>
              {t(owner ? 'roles.gate.ownerBody' : 'roles.gate.managerBody', {
                store: me.merchant.name,
              })}
            </AlertDescription>
          </AlertContent>
        </Alert>
      </div>
    </div>
  );
}
