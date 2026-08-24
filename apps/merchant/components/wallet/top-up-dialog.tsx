'use client';

import { useState } from 'react';
import {
  bankLabel,
  parseMvrToLaari,
  type SettlementDestination,
  type WalletTopUp,
} from '@manfaa/api-client';
import { useFormatMoney } from '@manfaa/ui';
import { LoaderCircle, ShieldCheck, TriangleAlert, Upload } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
  apiErrorCode,
  apiErrorMessage,
  hasFieldError,
  useCreateWalletTopUp,
} from '@/lib/queries';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TransferDestination } from '@/components/settlement/payment-instructions';
import { SlipDropzone } from '@/components/settlement/receipt-form';

/**
 * Topping up the wallet by bank transfer (owner, 2026-08-24), through the
 * SAME receipt-first shape a settlement uses: pick the platform account,
 * transfer at your own bank, upload the slip, quote the reference if you
 * have it. The bank picker and the slip dropzone ARE the settlement
 * wizard's — shared components, not copies — so the two flows look and
 * behave alike, and a fix to one is a fix to both.
 *
 * Submitting creates a CLAIM, never balance: the server watches the named
 * account's bank history and credits the wallet only once the transfer is
 * found (or an admin matches it). The success state says exactly that.
 *
 * The amount is parsed with parseMvrToLaari (string decomposition), so no
 * money value passes through a float; the floor AND the platform's accounts
 * are the wallet payload's (`top_up_min_laari`, `bank_accounts`), so a store
 * that has never settled can still fund itself.
 */
export function TopUpDialog({
  open,
  onOpenChange,
  minLaari,
  accounts,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The platform floor for a claim, integer laari (`top_up_min_laari`). */
  minLaari: number;
  /** Where a top-up may be sent (`bank_accounts` on the wallet payload). */
  accounts: SettlementDestination[];
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[calc(100vh-2rem)]">
        {/* All form state lives in the body, which Radix unmounts on close:
            reopening the dialog starts clean without any reset logic. */}
        <TopUpBody
          minLaari={minLaari}
          accounts={accounts}
          onClose={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  );
}

function safeParseMvr(input: string): number | null {
  try {
    return parseMvrToLaari(input);
  } catch {
    return null;
  }
}

function TopUpBody({
  minLaari,
  accounts,
  onClose,
}: {
  minLaari: number;
  accounts: SettlementDestination[];
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const createTopUp = useCreateWalletTopUp();

  const [amountInput, setAmountInput] = useState('');
  const [destinationId, setDestinationId] = useState<number | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [bankRef, setBankRef] = useState('');
  const [touched, setTouched] = useState(false);
  const [created, setCreated] = useState<WalletTopUp | null>(null);

  // Preselected ONLY when there is nothing to choose. With two banks on
  // offer a default is a decision made for someone about where their money
  // goes — the same rule the settlement wizard applies.
  const chosenId =
    destinationId ?? (accounts.length === 1 ? accounts[0].id : null);

  const amountLaari = safeParseMvr(amountInput);
  const amountInvalid = amountLaari === null || amountLaari < 1;
  const belowMinimum = !amountInvalid && amountLaari < minLaari;
  const destinationMissing = chosenId === null;
  const canSubmit =
    !amountInvalid &&
    !belowMinimum &&
    !destinationMissing &&
    file !== null &&
    !createTopUp.isPending;

  const submit = () => {
    setTouched(true);
    if (!canSubmit || amountLaari === null || chosenId === null || file === null) {
      return;
    }
    createTopUp.mutate(
      {
        amount: amountLaari,
        platform_bank_account_id: chosenId,
        slip: file,
        bank_ref: bankRef.trim() === '' ? undefined : bankRef.trim(),
      },
      { onSuccess: (response) => setCreated(response.data) },
    );
  };

  // -----------------------------------------------------------------------
  // Done: the claim is in and the bank is being read. Not "topped up" — the
  // balance has not moved, and the merchant must not go looking for it.
  // -----------------------------------------------------------------------
  if (created !== null) {
    const bank = created.platform_bank_account ?? null;
    return (
      <>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-green-500/15">
              <ShieldCheck className="size-5 text-green-600" />
            </span>
            {t('wallet.topUpDialog.successTitle')}
          </DialogTitle>
        </DialogHeader>
        <DialogBody>
          <p className="text-sm text-secondary-foreground">
            {bank
              ? t('wallet.topUpDialog.successBody', {
                  amount: formatMoney(created.amount_laari, created.currency),
                  bank: bankLabel(bank.bank_name),
                })
              : t('wallet.topUpDialog.successBodyNoBank', {
                  amount: formatMoney(created.amount_laari, created.currency),
                })}
          </p>
        </DialogBody>
        <DialogFooter>
          <Button onClick={onClose}>{t('wallet.topUpDialog.done')}</Button>
        </DialogFooter>
      </>
    );
  }

  const error = createTopUp.error;
  const code = apiErrorCode(error);
  const slipRefused = code?.startsWith('slip_') === true;
  // The floor arrives as a FIELD error on `amount` (the request rule fires
  // before the domain), e.g. when an admin raised it after this form
  // loaded — said in the merchant's terms, never Laravel's laari sentence.
  const errorTitle = hasFieldError(error, 'amount')
    ? t('wallet.topUpDialog.amountBelowMin', { min: formatMoney(minLaari) })
    : code === 'duplicate_bank_ref'
      ? t('wallet.topUpDialog.duplicateBankRef')
      : code === 'too_many_pending_top_ups'
        ? t('wallet.topUpDialog.tooManyPending')
        : code === 'store_not_approved'
          ? t('wallet.topUpDialog.storeNotApproved')
          : code === 'permission_required'
            ? t('wallet.topUpDialog.permissionRequired')
            : slipRefused
              ? apiErrorMessage(error, t('settlement.fileUnsupported'))
              : apiErrorMessage(error, t('wallet.topUpDialog.submitFailed'));

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t('wallet.topUpDialog.title')}</DialogTitle>
        <DialogDescription>{t('wallet.topUpDialog.lead')}</DialogDescription>
      </DialogHeader>

      <DialogBody className="flex min-h-0 flex-col gap-5 overflow-y-auto">
        <div className="flex flex-col gap-2.5">
          <Label htmlFor="top-up-amount">
            {t('wallet.topUpDialog.amountLabel')}
          </Label>
          <InputGroup className="sm:w-64">
            <InputAddon>MVR</InputAddon>
            <Input
              id="top-up-amount"
              inputMode="decimal"
              dir="ltr"
              autoFocus
              value={amountInput}
              onChange={(event) => setAmountInput(event.target.value)}
              aria-invalid={(amountInvalid || belowMinimum) && touched}
            />
          </InputGroup>
          {amountInvalid && touched ? (
            <p className="text-xs text-destructive">
              {t('wallet.topUpDialog.amountInvalid')}
            </p>
          ) : belowMinimum ? (
            <p className="text-xs text-destructive">
              {t('wallet.topUpDialog.amountBelowMin', {
                min: formatMoney(minLaari),
              })}
            </p>
          ) : (
            <p className="text-xs text-muted-foreground">
              {t('wallet.topUpDialog.amountHint', {
                min: formatMoney(minLaari),
              })}
            </p>
          )}
        </div>

        {accounts.length === 0 ? (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertTitle>{t('wallet.topUpDialog.noDestinations')}</AlertTitle>
          </Alert>
        ) : (
          <>
            <TransferDestination
              bankAccount={accounts[0] ?? null}
              bankAccounts={accounts}
              selectedAccountId={chosenId}
              onSelectAccount={setDestinationId}
              needsConfiguration={false}
            />
            {destinationMissing && touched && (
              <p className="-mt-3 text-xs text-destructive">
                {t('wallet.topUpDialog.chooseBankFirst')}
              </p>
            )}
          </>
        )}

        <div className="flex flex-col gap-2.5">
          <Label>{t('wallet.topUpDialog.slipLabel')}</Label>
          <SlipDropzone
            file={file}
            onFile={setFile}
            requiredMessage={
              touched && file === null ? t('settlement.fileRequired') : null
            }
          />
        </div>

        <div className="flex flex-col gap-2.5">
          <Label htmlFor="top-up-bank-ref">
            {t('wallet.topUpDialog.bankRefLabel')}{' '}
            <span className="font-normal text-muted-foreground">
              ({t('common.optional')})
            </span>
          </Label>
          <Input
            id="top-up-bank-ref"
            dir="ltr"
            maxLength={128}
            value={bankRef}
            onChange={(event) => setBankRef(event.target.value)}
            className="sm:w-80"
          />
          <p className="text-xs text-muted-foreground">
            {t('wallet.topUpDialog.bankRefHint')}
          </p>
        </div>

        {error !== null && error !== undefined && (
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>{errorTitle}</AlertTitle>
              {slipRefused && (
                <AlertDescription>
                  {t('settlement.dropzoneHint')}
                </AlertDescription>
              )}
            </AlertContent>
          </Alert>
        )}
      </DialogBody>

      <DialogFooter>
        <Button
          variant="outline"
          onClick={onClose}
          disabled={createTopUp.isPending}
        >
          {t('common.cancel')}
        </Button>
        <Button onClick={submit} disabled={createTopUp.isPending}>
          {createTopUp.isPending ? (
            <LoaderCircle className="animate-spin" />
          ) : (
            <Upload />
          )}
          {t('wallet.topUpDialog.submit')}
        </Button>
      </DialogFooter>
    </>
  );
}
