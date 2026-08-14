'use client';

import { ReactNode } from 'react';
import { ShieldAlert } from 'lucide-react';
import { useLayout } from '@/components/app-layout/context';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';

/**
 * Settings are owner-only. This client-side gate is a courtesy for staff
 * who deep-link in — the sidebar already hides the section — while the API
 * enforces the same rule server-side (403 owner_required) on every route.
 */
export default function SettingsLayout({ children }: { children: ReactNode }) {
  const { me } = useLayout();

  if (me.role !== 'owner') {
    return (
      <div className="container">
        <div className="max-w-lg pt-10">
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <ShieldAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>Owner access only</AlertTitle>
              <AlertDescription>
                Store settings can only be changed by the account owner. Ask
                the owner of {me.merchant.name} if something here needs to
                change.
              </AlertDescription>
            </AlertContent>
          </Alert>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
