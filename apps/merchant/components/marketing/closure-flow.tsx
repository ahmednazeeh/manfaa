'use client';

import { useState } from 'react';
import { z } from 'zod';
import { ApiError, apiFetch, bootstrapCsrf } from '@manfaa/api-client';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const VerifySchema = z.object({
  data: z.object({
    closure_token: z.string(),
    stores: z.array(
      z.object({
        id: z.number(),
        name: z.string(),
        status: z.string(),
        outstanding_laari: z.number(),
        can_close: z.boolean(),
      }),
    ),
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
    if (code === 'no_store') return 'No store is registered with this contact number.';
    if (code === 'outstanding_balance') return 'This store still owes a settlement — settle first, then close.';
    if (code === 'closure_token_invalid') return 'This confirmation expired — start again.';
    if (error.status === 422) return 'Enter the store’s contact number — a Maldivian mobile starting with 7 or 9.';
  }
  return 'Could not reach the server. Try again.';
}

/**
 * Self-service store closure (store-readiness 2026-08-17): possession of
 * the store's contact number, proven by OTP, is the credential — the
 * owner may have lost panel access entirely. A store owing money shows
 * its balance and a disabled Close: settling stays open, closing waits.
 */
export function ClosureFlow() {
  const [step, setStep] = useState<'phone' | 'code' | 'stores' | 'done'>('phone');
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [token, setToken] = useState('');
  const [stores, setStores] = useState<z.infer<typeof VerifySchema>['data']['stores']>([]);
  const [closed, setClosed] = useState<string | null>(null);
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
      await apiFetch('/api/merchant/account-closure/request-otp', MessageSchema, {
        method: 'POST',
        body: { phone },
      });
      setStep('code');
    });

  const verify = () =>
    run(async () => {
      const response = await apiFetch('/api/merchant/account-closure/verify', VerifySchema, {
        method: 'POST',
        body: { phone, code },
      });
      setToken(response.data.closure_token);
      setStores(response.data.stores);
      setStep('stores');
    });

  const close = (merchantId: number, name: string) =>
    run(async () => {
      await apiFetch('/api/merchant/account-closure/confirm', MessageSchema, {
        method: 'POST',
        body: { closure_token: token, merchant_id: merchantId },
      });
      setClosed(name);
      setStep('done');
    });

  return (
    <div className="rounded-2xl border border-border bg-muted/30 p-6">
      {step === 'phone' && (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-semibold text-mono">
            Start here: verify the store&rsquo;s contact number
          </p>
          <p className="text-sm text-muted-foreground">
            Enter the contact number your store is registered with and
            we&rsquo;ll text it a 6-digit code.
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

      {step === 'stores' && (
        <div className="flex flex-col gap-4">
          <p className="text-sm font-semibold text-mono">
            Stores on this number — closing is permanent
          </p>
          {stores.map((store) => (
            <div
              key={store.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-background p-4"
            >
              <div className="flex flex-col">
                <span className="text-sm font-medium text-mono">{store.name}</span>
                <span className="text-xs text-muted-foreground">
                  {store.can_close
                    ? 'Fully settled — can be closed.'
                    : `Outstanding: ${mvr(store.outstanding_laari)} — settle in the panel first.`}
                </span>
              </div>
              <Button
                variant="destructive"
                disabled={busy || !store.can_close}
                onClick={() => close(store.id, store.name)}
              >
                Close store
              </Button>
            </div>
          ))}
        </div>
      )}

      {step === 'done' && (
        <p className="text-sm text-muted-foreground">
          <strong className="text-foreground">{closed} is now closed.</strong>{' '}
          It has left the Manfaa storefront, crediting has stopped, and all
          staff access is disabled.
        </p>
      )}

      {error && <p className="mt-3 text-sm text-destructive">{error}</p>}
    </div>
  );
}
