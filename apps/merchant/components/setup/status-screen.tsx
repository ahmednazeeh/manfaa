'use client';

import { type MerchantSetupState } from '@manfaa/api-client';
import { format } from 'date-fns';
import { Hourglass, PencilLine } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

/**
 * Post-submit lifecycle screens (§1 decision 2026-08-15):
 *  - pending_review — "being reviewed by Manfaa", the panel stays locked;
 *  - rejected — the admin's reason verbatim plus "Edit and resubmit",
 *    which drops the owner back into the wizard.
 */
export function SetupStatusScreen({
  state,
  onEdit,
}: {
  state: MerchantSetupState;
  onEdit: () => void;
}) {
  const { t } = useTranslation();
  const rejected = state.status === 'rejected';

  return (
    <Card className="w-full">
      <CardContent className="p-8 flex flex-col items-center gap-5 text-center">
        <span
          className={
            rejected
              ? 'size-14 rounded-full bg-warning/10 text-warning inline-flex items-center justify-center'
              : 'size-14 rounded-full bg-primary/10 text-primary inline-flex items-center justify-center'
          }
        >
          {rejected ? (
            <PencilLine className="size-6" />
          ) : (
            <Hourglass className="size-6" />
          )}
        </span>

        <div className="flex flex-col gap-1.5">
          <h1 className="text-lg font-semibold text-mono">
            {rejected ? t('setup.rejectedTitle') : t('setup.pendingTitle')}
          </h1>
          <p className="text-sm text-muted-foreground max-w-md">
            {rejected ? t('setup.rejectedBody') : t('setup.pendingBody')}
          </p>
        </div>

        {rejected && state.rejected_reason !== null && (
          <blockquote className="w-full max-w-md text-sm text-secondary-foreground bg-muted/60 border border-border rounded-md p-4 text-start whitespace-pre-wrap">
            {state.rejected_reason}
          </blockquote>
        )}

        {!rejected && state.submitted_at !== null && (
          <p className="text-xs text-muted-foreground">
            {t('setup.pendingSubmittedAt', {
              date: format(new Date(state.submitted_at), 'dd MMM yyyy, HH:mm'),
            })}
          </p>
        )}

        {rejected && (
          <Button onClick={onEdit}>
            <PencilLine />
            {t('setup.editAndResubmit')}
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
