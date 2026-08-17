'use client';

import { useState } from 'react';
import { z } from 'zod';
import { ApiError, apiFetch, bootstrapCsrf } from '@manfaa/api-client';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const VerifySchema = z.object({
  data: z.object({
    deletion_token: z.string(),
    name: z.string(),
    customer_code: z.string(),
    confirmed_laari: z.number(),
    pending_laari: z.number(),
  }),
});
const MessageSchema = z.object({ message: z.string() });

const mvr = (laari: number) =>
  `MVR ${(laari / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function errorText(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) return 'Too many attempts — wait a minute and try again.';
    const body = error.body as { errors?: Record<string, string[]> } | undefined;
    const code = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;
    if (code === 'otp_invalid') return 'That code is not right. Check the SMS and try again.';
    if (code === 'otp_attempts_exceeded') return 'Too many wrong codes — request a new one.';
    if (code === 'no_account') return 'There is no Manfaa account on this number.';
    if (code === 'deletion_token_invalid') return 'This confirmation expired — start again.';
    if (error.status === 422) return 'Enter a Maldivian mobile number starting with 7 or 9.';
  }
  return 'Could not reach the server. Try again.';
}

/**
 * The self-service deletion flow (store-readiness 2026-08-17): phone →
 * OTP → an honest summary of what lapses → the irreversible click. This
 * page is also the web-deletion URL the app stores point at, so it must
 * work for someone who has already uninstalled the app.
 */
export function DeletionFlow() {
  const [step, setStep] = useState<'phone' | 'code' | 'confirm' | 'done'>('phone');
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [account, setAccount] = useState<z.infer<typeof VerifySchema>['data'] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const requestCode = () =>
    run(async () => {
      await bootstrapCsrf();
      await apiFetch('/api/customer/account-deletion/request-otp', MessageSchema, {
        method: 'POST',
        body: { phone },
      });
      setStep('code');
    });

  const verify = () =>
    run(async () => {
      const response = await apiFetch('/api/customer/account-deletion/verify', VerifySchema, {
        method: 'POST',
        body: { phone, code },
      });
      setAccount(response.data);
      setStep('confirm');
    });

  const confirm = () =>
    run(async () => {
      if (!account) return;
      await apiFetch('/api/customer/account-deletion/confirm', MessageSchema, {
        method: 'POST',
        body: { deletion_token: account.deletion_token },
      });
      setStep('done');
    });

  return (
    <div className="rounded-2xl border border-border bg-muted/30 p-6">
      {step === 'phone' && (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-semibold text-mono">
            Start here: verify your number
          </p>
          <p className="text-sm text-muted-foreground">
            Enter the mobile number your account is registered to and
            we&rsquo;ll text you a 6-digit code.
          </p>
          <div className="flex flex-wrap gap-2">
            <Input
              dir="ltr"
              inputMode="tel"
              placeholder="7XXXXXX"
              className="max-w-48"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
            <Button onClick={requestCode} disabled={busy || phone.trim() === ''}>
              Send code
            </Button>
          </div>
        </div>
      )}

      {step === 'code' && (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-semibold text-mono">Enter the code we sent to {phone}</p>
          <div className="flex flex-wrap gap-2">
            <Input
              dir="ltr"
              inputMode="numeric"
              maxLength={6}
              placeholder="123456"
              className="max-w-32"
              value={code}
              onChange={(e) => setCode(e.target.value)}
            />
            <Button onClick={verify} disabled={busy || code.trim().length !== 6}>
              Verify
            </Button>
            <Button variant="ghost" onClick={requestCode} disabled={busy}>
              Resend
            </Button>
          </div>
        </div>
      )}

      {step === 'confirm' && account && (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-semibold text-mono">
            Delete the account of {account.name} (member {account.customer_code})?
          </p>
          <ul className="list-disc space-y-1.5 ps-5 text-sm text-muted-foreground">
            <li>
              Confirmed balance of <strong className="text-foreground">{mvr(account.confirmed_laari)}</strong> and
              pending cashback of <strong className="text-foreground">{mvr(account.pending_laari)}</strong>{' '}
              <strong className="text-foreground">lapse permanently</strong> — if you want a final payout,
              wait for the next payout run before deleting.
            </li>
            <li>Your name, number, email and bank details are removed immediately.</li>
            <li>This cannot be undone.</li>
          </ul>
          <div>
            <Button variant="destructive" onClick={confirm} disabled={busy}>
              Delete my account permanently
            </Button>
          </div>
        </div>
      )}

      {step === 'done' && (
        <p className="text-sm text-muted-foreground">
          <strong className="text-foreground">Your account has been deleted.</strong>{' '}
          Thank you for having shopped with Manfaa — you are welcome back
          any time with a fresh account.
        </p>
      )}

      {error && <p className="mt-3 text-sm text-destructive">{error}</p>}
    </div>
  );
}
