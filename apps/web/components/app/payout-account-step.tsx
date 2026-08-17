'use client';

import { useState } from 'react';
import {
  apiErrorCode,
  BankSlugSchema,
  type BankSlug,
} from '@manfaa/api-client';
import { LoaderCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { useRequestPayoutOtp, useSavePayoutAccount } from '@/lib/queries';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BankSelect } from '@/components/app/bank-select';

/**
 * The last step of signup: where the customer's cashback should be sent.
 *
 * Deliberately SKIPPABLE. Cashback accrues whether or not an account is on
 * file — the details only matter when a payout batch runs — so demanding
 * them at the door would turn a two-minute signup into a "go and find your
 * account number" errand, and lose the person who cannot.
 *
 * Asking here rather than leaving the dashboard's nudge to do the work is
 * still worth a step: this is the moment someone is already filling a form
 * and thinking about money. A customer over the payout minimum with
 * incomplete details is skipped by the batch builder and simply waits, which
 * is the outcome this step exists to make rare.
 */
export function PayoutAccountStep({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation();
  const save = useSavePayoutAccount();
  const otp = useRequestPayoutOtp();

  const [bank, setBank] = useState<BankSlug | ''>('');
  const [accountNo, setAccountNo] = useState('');
  const [accountName, setAccountName] = useState('');
  // The fresh-OTP gate rides here too: even minutes after signup, changing
  // where money goes demands a code to the number on file.
  const [codeSent, setCodeSent] = useState(false);
  const [otpCode, setOtpCode] = useState('');

  const canSave =
    bank !== '' &&
    accountNo.trim() !== '' &&
    accountName.trim() !== '' &&
    !save.isPending &&
    !otp.isPending;

  const submit = () => {
    const parsed = BankSlugSchema.safeParse(bank);
    if (!parsed.success) return;

    if (!codeSent) {
      otp.mutate(undefined, {
        onSuccess: () => {
          setCodeSent(true);
          toast.success(t('payout.otpSent'));
        },
        onError: () => toast.error(t('payout.otpSendFailed')),
      });
      return;
    }

    if (!/^\d{6}$/.test(otpCode)) {
      toast.error(t('payout.otpInvalid'));
      return;
    }

    save.mutate(
      {
        bank_name: parsed.data,
        account_no: accountNo.trim(),
        account_name: accountName.trim(),
        otp_code: otpCode,
      },
      {
        onSuccess: () => {
          toast.success(t('payout.saved'));
          onDone();
        },
        onError: (mutationError) => {
          const code = apiErrorCode(mutationError);
          toast.error(
            code === 'otp_invalid' || code === 'otp_attempts_exceeded'
              ? t('payout.otpInvalid')
              : t('payout.saveFailed'),
          );
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold text-mono">
          {t('payout.setupTitle')}
        </h2>
        <p className="text-sm text-muted-foreground">
          {t('payout.setupSubtitle')}
        </p>
      </div>

      <div className="flex flex-col gap-2.5">
        <Label htmlFor="signup-bank">{t('payout.bankLabel')}</Label>
        <BankSelect
          id="signup-bank"
          value={bank}
          onChange={setBank}
          placeholder={t('payout.bankPlaceholder')}
        />
      </div>

      <div className="flex flex-col gap-2.5">
        <Label htmlFor="signup-account-no">{t('payout.accountNoLabel')}</Label>
        <Input
          id="signup-account-no"
          // The number is a run of digits and must not be reordered by the
          // bidi algorithm under the Dhivehi layout.
          dir="ltr"
          inputMode="numeric"
          value={accountNo}
          maxLength={64}
          onChange={(event) => setAccountNo(event.target.value)}
        />
      </div>

      <div className="flex flex-col gap-2.5">
        <Label htmlFor="signup-account-name">
          {t('payout.accountNameLabel')}
        </Label>
        <Input
          id="signup-account-name"
          value={accountName}
          maxLength={255}
          placeholder={t('payout.accountNamePlaceholder')}
          onChange={(event) => setAccountName(event.target.value)}
        />
        <p className="text-xs text-muted-foreground">
          {t('payout.accountNameHint')}
        </p>
      </div>

      {codeSent && (
        <div className="flex flex-col gap-2.5">
          <Label htmlFor="signup-payout-otp">{t('payout.otpLabel')}</Label>
          <Input
            id="signup-payout-otp"
            dir="ltr"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            placeholder="••••••"
            value={otpCode}
            onChange={(event) =>
              setOtpCode(event.target.value.replace(/\D/g, ''))
            }
          />
          <p className="text-xs text-muted-foreground">
            {t('payout.otpSentNote')}
          </p>
        </div>
      )}

      <div className="flex flex-col gap-2.5">
        <Button disabled={!canSave} onClick={submit}>
          {(save.isPending || otp.isPending) && (
            <LoaderCircle className="animate-spin" />
          )}
          {codeSent ? t('payout.saveAndFinish') : t('payout.sendCode')}
        </Button>
        <Button variant="ghost" onClick={onDone} disabled={save.isPending}>
          {t('payout.skipForNow')}
        </Button>
        <p className="text-center text-xs text-muted-foreground">
          {t('payout.skipHint')}
        </p>
      </div>
    </div>
  );
}
