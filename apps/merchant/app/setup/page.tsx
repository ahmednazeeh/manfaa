'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { LogOut, Moon, ShieldAlert, Sun } from 'lucide-react';
import { useTheme } from 'next-themes';
import { useTranslation } from 'react-i18next';
import { isOnboardingStatus } from '@/lib/api';
import { toAbsoluteUrl } from '@/lib/helpers';
import { can } from '@/lib/roles';
import { isUnauthorized, useLogout, useMe, useSetupState } from '@/lib/queries';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorBlock } from '@/components/app/async-states';
import { LanguageSwitcher } from '@/components/app/language-switcher';
import { ScreenLoader } from '@/components/screen-loader';
import { SetupWizard } from '@/components/setup/setup-wizard';
import { SetupStatusScreen } from '@/components/setup/status-screen';

/**
 * /setup — the only screen a draft / pending_review / rejected merchant can
 * reach (the (app) layout bounces those statuses here, and this page
 * bounces everyone else back to the panel). Renders, by lifecycle state:
 *
 *  - draft                → the resumable wizard;
 *  - pending_review       → the "being reviewed by Manfaa" screen;
 *  - rejected             → the same screen with the admin's reason and an
 *                           "Edit and resubmit" path back into the wizard.
 *
 * The wizard stands on `setup.view` / `setup.edit`, which only the seeded
 * Owner role holds (the API enforces it; anyone else sees a notice). The
 * only actions besides the wizard itself are language/theme and logout.
 */
export default function SetupPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const { data: me, isPending, error } = useMe();
  const logoutMutation = useLogout();

  // Rejected stores show the status screen first; "Edit and resubmit"
  // switches into the wizard until the next submit.
  const [editingAfterRejection, setEditingAfterRejection] = useState(false);

  // Reading the wizard's state and FILLING it are separate grants (the API
  // gates them as setup.view / setup.edit): an account that may only look
  // gets the status screen, never a form whose every save would be refused.
  const canOpenSetup = me !== undefined && can(me, 'setup.view');
  const canEditSetup = me !== undefined && can(me, 'setup.edit');
  const belongsHere =
    me !== undefined && isOnboardingStatus(me.merchant.status);

  useEffect(() => {
    if (isUnauthorized(error)) {
      router.replace('/login');
    } else if (me !== undefined && !belongsHere) {
      router.replace('/dashboard');
    }
  }, [error, me, belongsHere, router]);

  // The setup state needs setup.view server-side; skip the query otherwise.
  const setupEnabled = canOpenSetup && belongsHere;
  const setup = useSetupState(setupEnabled);

  const handleLogout = () => {
    logoutMutation.mutate(undefined, {
      onSettled: () => {
        router.replace('/login');
      },
    });
  };

  if (
    isPending ||
    isUnauthorized(error) ||
    (me !== undefined && !belongsHere)
  ) {
    return <ScreenLoader />;
  }

  if (error || me === undefined) {
    return (
      <div className="grow flex items-center justify-center min-h-screen">
        <div className="w-full max-w-md">
          <ErrorBlock error={error} fallback={t('common.serverUnreachable')} />
        </div>
      </div>
    );
  }

  const state = setupEnabled ? setup.data : undefined;
  const showWizard =
    canEditSetup &&
    state !== undefined &&
    (state.status === 'draft' ||
      (state.status === 'rejected' && editingAfterRejection));

  return (
    <div className="grow min-h-screen w-full bg-muted/40 flex flex-col">
      <header className="flex items-center gap-2.5 px-5 py-4">
        <img
          src={toAbsoluteUrl('/media/app/default-logo.svg')}
          className="dark:hidden h-[22px]"
          alt={t('common.appName')}
        />
        <img
          src={toAbsoluteUrl('/media/app/default-logo-dark.svg')}
          className="hidden dark:block h-[22px]"
          alt={t('common.appName')}
        />
        <div className="ms-auto flex items-center gap-1.5">
          <LanguageSwitcher />
          <SetupThemeToggle />
          <Button
            variant="ghost"
            onClick={handleLogout}
            disabled={logoutMutation.isPending}
          >
            <LogOut />
            {t('common.logOut')}
          </Button>
        </div>
      </header>

      <main className="grow flex justify-center px-5 pb-10 pt-4">
        <div className="w-full max-w-3xl">
          {!canOpenSetup ? (
            <Alert variant="warning" appearance="light">
              <AlertIcon>
                <ShieldAlert />
              </AlertIcon>
              <AlertContent>
                <AlertTitle>{t('setup.ownerOnlyTitle')}</AlertTitle>
                <AlertDescription>{t('setup.ownerOnlyBody')}</AlertDescription>
              </AlertContent>
            </Alert>
          ) : setup.error ? (
            <ErrorBlock
              error={setup.error}
              fallback={t('common.serverUnreachable')}
            />
          ) : state === undefined ? (
            <ScreenLoader />
          ) : showWizard ? (
            <SetupWizard
              // Remount when the lifecycle flips (e.g. submit succeeded →
              // rejected again later) so step state resets cleanly.
              key={state.status}
              state={state}
            />
          ) : (
            <SetupStatusScreen
              state={state}
              onEdit={() => setEditingAfterRejection(true)}
            />
          )}
        </div>
      </main>
    </div>
  );
}

function SetupThemeToggle() {
  const { t } = useTranslation();
  const { resolvedTheme, setTheme } = useTheme();

  return (
    <Button
      variant="ghost"
      mode="icon"
      shape="circle"
      aria-label={t('common.toggleDarkMode')}
      className="size-9 hover:bg-primary/10 hover:[&_svg]:text-primary"
      onClick={() => setTheme(resolvedTheme === 'dark' ? 'light' : 'dark')}
    >
      <Sun className="size-4.5! dark:hidden" />
      <Moon className="size-4.5! hidden dark:block" />
    </Button>
  );
}
