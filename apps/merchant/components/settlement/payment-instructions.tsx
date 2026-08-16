'use client';

import { MoneyText } from '@manfaa/ui';
import { Landmark, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { bankLabel, type SettlementDestination } from '@manfaa/api-client';
import type { PlatformBankAccount } from '@/lib/api';
import { cn } from '@/lib/utils';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { BankLabel, BankLogo } from '@/components/app/bank-select';
import { CopyButton } from '@/components/settlement/copy-button';

/**
 * Where to send the transfer, what to send, and what to quote (PLAN §1
 * receipt-first). Every value the merchant has to retype into their banking
 * app carries a copy button, and each one is rendered dir="ltr" so account
 * numbers and references read correctly in the Dhivehi (RTL) layout.
 *
 * When the platform has no active primary account the details are absent —
 * never invented — and the merchant is told to contact Manfaa.
 */
export function PaymentInstructions({
  reference,
  referenceIsFinal,
  amountDueLaari,
  bankAccount,
  bankAccounts,
  selectedAccountId,
  onSelectAccount,
  needsConfiguration,
}: {
  reference: string;
  /** false on a preview: the batch's real reference is set at submit. */
  referenceIsFinal: boolean;
  amountDueLaari: number;
  bankAccount: PlatformBankAccount | null;
  /** Every account the merchant may send to — one per bank. */
  bankAccounts?: SettlementDestination[];
  selectedAccountId?: number | null;
  onSelectAccount?: (id: number) => void;
  needsConfiguration: boolean;
}) {
  const { t } = useTranslation();

  const choices = bankAccounts ?? [];
  // A choice is only offered when there is genuinely one to make. With a
  // single configured account a picker is a control that can only be set to
  // what it already says.
  const choosable = choices.length > 1 && onSelectAccount !== undefined;
  const chosen =
    choices.find((candidate) => candidate.id === selectedAccountId) ?? null;

  const account = needsConfiguration ? null : (chosen ?? bankAccount);

  return (
    <div className="flex flex-col gap-5">
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <span className="text-xs uppercase text-muted-foreground">
            {t('settlement.amountToTransfer')}
          </span>
          <MoneyText
            laari={amountDueLaari}
            className="text-2xl font-semibold text-mono"
          />
        </div>
        <div className="flex flex-col gap-1">
          <span className="text-xs uppercase text-muted-foreground">
            {t('settlement.referenceLabel')}
          </span>
          <div className="flex items-center gap-2">
            <code
              dir="ltr"
              className="rounded-md bg-muted px-2.5 py-1.5 text-sm font-semibold text-mono"
            >
              {reference}
            </code>
            <CopyButton
              value={reference}
              label={t('settlement.copyReference')}
            />
          </div>
          <span className="text-xs text-muted-foreground">
            {referenceIsFinal
              ? t('settlement.referenceHint')
              : t('settlement.referencePreviewNote')}
          </span>
        </div>
      </div>

      {account !== null ? (
        <div className="rounded-md border border-border p-4">
          <div className="mb-3 text-xs uppercase text-muted-foreground">
            {t('settlement.transferTo')}
          </div>

          {choosable && (
            <div className="mb-4 flex flex-col gap-2">
              <span className="text-xs text-muted-foreground">
                {t('settlement.chooseBank')}
              </span>
              <div className="grid gap-2 sm:grid-cols-2">
                {choices.map((candidate) => {
                  const active = candidate.id === chosen?.id;
                  return (
                    <button
                      key={candidate.id}
                      type="button"
                      onClick={() => onSelectAccount?.(candidate.id)}
                      aria-pressed={active}
                      className={cn(
                        'flex items-center gap-3 rounded-md border p-3 text-start transition-colors',
                        active
                          ? 'border-primary bg-primary/5'
                          : 'border-border hover:border-primary/40',
                      )}
                    >
                      <BankLogo bank={candidate.bank_name} />
                      <span className="flex min-w-0 flex-col">
                        <span className="truncate text-sm font-medium text-mono">
                          {bankLabel(candidate.bank_name)}
                        </span>
                        <span className="truncate text-xs text-muted-foreground" dir="ltr">
                          {candidate.account_no}
                        </span>
                      </span>
                    </button>
                  );
                })}
              </div>
              <span className="text-xs text-muted-foreground">
                {t('settlement.chooseBankHint')}
              </span>
            </div>
          )}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-1">
              <span className="text-xs text-muted-foreground">
                {t('settlement.bankName')}
              </span>
              <div className="flex items-center gap-2">
                <BankLabel
                  bank={account.bank_name}
                  className="text-sm font-medium text-mono"
                />
                <CopyButton
                  value={bankLabel(account.bank_name)}
                  label={t('settlement.copyBankName')}
                />
              </div>
            </div>
            <div className="flex flex-col gap-1">
              <span className="text-xs text-muted-foreground">
                {t('settlement.accountNo')}
              </span>
              <div className="flex items-center gap-2">
                <code
                  dir="ltr"
                  className="rounded-md bg-muted px-2.5 py-1.5 text-sm font-semibold text-mono"
                >
                  {account.account_no}
                </code>
                <CopyButton
                  value={account.account_no}
                  label={t('settlement.copyAccountNo')}
                />
              </div>
            </div>
            <div className="flex flex-col gap-1">
              <span className="text-xs text-muted-foreground">
                {t('settlement.accountName')}
              </span>
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-mono" dir="ltr">
                  {account.account_name}
                </span>
                <CopyButton
                  value={account.account_name}
                  label={t('settlement.copyAccountName')}
                />
              </div>
            </div>
          </div>
        </div>
      ) : (
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>{t('settlement.noAccountTitle')}</AlertTitle>
            <AlertDescription>{t('settlement.noAccountBody')}</AlertDescription>
          </AlertContent>
        </Alert>
      )}

      <p className="flex items-start gap-2 text-sm text-secondary-foreground">
        <Landmark className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
        {t('settlement.reviewLead')}
      </p>
    </div>
  );
}
