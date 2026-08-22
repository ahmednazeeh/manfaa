'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ApiError } from '@manfaa/api-client';
import { BrandMark } from '@manfaa/ui';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { normalizeMaldivesPhone } from '@/lib/format';
import {
  useRegister,
  useRequestOtp,
  useVerifyOtpForAccess,
  validationErrorKeys,
} from '@/lib/queries';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Passwordless sign-in (owner decision 2026-08-18). There is no password on
 * a Manfaa membership at all — the customer app has always worked this way,
 * and the web now matches it: a phone number, the code we text, and you are
 * in. A number we have never seen finishes with a name in the same flow, so
 * "sign in" and "sign up" stop being a question the member has to answer.
 */
type Step = 'phone' | 'code' | 'name';

export default function LoginPage() {
  const { t } = useTranslation();
  const router = useRouter();

  const requestOtp = useRequestOtp();
  const verify = useVerifyOtpForAccess();
  const register = useRegister();

  const [step, setStep] = useState<Step>('phone');
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [signupToken, setSignupToken] = useState('');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const busy = requestOtp.isPending || verify.isPending || register.isPending;

  const describe = (error: unknown, fallback: string): string => {
    const keys = validationErrorKeys(error);
    if (keys.includes('otp_attempts_exceeded')) {
      return t('auth.codeAttemptsExceeded');
    }
    if (keys.includes('otp_invalid')) return t('auth.codeInvalid');
    if (keys.includes('signup_token_invalid')) {
      return t('auth.codeExpiredOrInvalidToken');
    }
    if (keys.includes('phone_already_registered')) {
      return t('auth.phoneAlreadyRegistered');
    }
    if (error instanceof ApiError && error.status === 429) {
      return t('common.tooManyAttempts');
    }
    return fallback;
  };

  const sendCode = () => {
    const normalized = normalizeMaldivesPhone(phone);
    if (normalized === null) {
      setErrorMessage(t('auth.phoneInvalid'));
      return;
    }
    setErrorMessage(null);
    requestOtp.mutate(normalized, {
      onSuccess: () => {
        setPhone(normalized);
        setStep('code');
      },
      onError: (error) =>
        setErrorMessage(describe(error, t('common.serverUnreachable'))),
    });
  };

  const submitCode = () => {
    if (!/^\d{6}$/.test(code)) return;
    setErrorMessage(null);
    verify.mutate(
      { phone, code },
      {
        onSuccess: (response) => {
          const data = response.data as Record<string, unknown>;
          // A known number is already signed in; an unknown one comes back
          // with the token that finishes registration.
          if (typeof data.signup_token === 'string') {
            setSignupToken(data.signup_token);
            setStep('name');
            return;
          }
          router.replace('/');
        },
        onError: (error) =>
          setErrorMessage(describe(error, t('common.serverUnreachable'))),
      },
    );
  };

  const finish = () => {
    if (name.trim() === '') {
      setErrorMessage(t('auth.nameRequired'));
      return;
    }
    setErrorMessage(null);
    register.mutate(
      { signup_token: signupToken, name: name.trim() },
      {
        onSuccess: () => router.replace('/'),
        onError: (error) =>
          setErrorMessage(describe(error, t('common.serverUnreachable'))),
      },
    );
  };

  return (
    <div className="relative grow flex items-center justify-center min-h-screen w-full bg-muted/40 p-5">
      <Card className="w-full max-w-[400px]">
        <CardContent className="p-8 flex flex-col gap-6">
          <div className="flex flex-col items-center gap-3">
            {/* Square here, not the wordmark: the card is 400px wide and a
                landscape mark either shrinks to unreadable or crowds it. */}
            <BrandMark
              shape="square"
              className="h-14 w-auto"
              alt={t('common.appName')}
            />
            <div className="text-center">
              <h1 className="text-lg font-semibold text-mono">
                {t('auth.loginTitle')}
              </h1>
              <p className="text-sm text-muted-foreground">
                {step === 'name'
                  ? t('auth.stepDetails')
                  : t('auth.loginSubtitle')}
              </p>
            </div>
          </div>

          {errorMessage && (
            <Alert variant="destructive" appearance="light" size="sm">
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertTitle>{errorMessage}</AlertTitle>
            </Alert>
          )}

          {step === 'phone' && (
            <div className="flex flex-col gap-5">
              <div className="flex flex-col gap-2">
                <Label htmlFor="phone">{t('auth.phoneLabel')}</Label>
                <Input
                  id="phone"
                  dir="ltr"
                  inputMode="tel"
                  autoComplete="tel"
                  placeholder={t('auth.phonePlaceholder')}
                  value={phone}
                  onChange={(event) => setPhone(event.target.value)}
                  onKeyDown={(event) => event.key === 'Enter' && sendCode()}
                />
                <p className="text-xs text-muted-foreground">
                  {t('auth.stepPhoneHint')}
                </p>
              </div>
              <Button className="w-full" onClick={sendCode} disabled={busy}>
                {busy && <LoaderCircle className="animate-spin" />}
                {t('auth.sendCode')}
              </Button>
            </div>
          )}

          {step === 'code' && (
            <div className="flex flex-col gap-5">
              <div className="flex flex-col gap-2">
                <Label htmlFor="code">{t('auth.stepCode')}</Label>
                <Input
                  id="code"
                  dir="ltr"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  placeholder="123456"
                  value={code}
                  onChange={(event) => setCode(event.target.value)}
                  onKeyDown={(event) => event.key === 'Enter' && submitCode()}
                />
                <p className="text-xs text-muted-foreground">
                  {t('auth.stepCodeHint')}
                </p>
              </div>
              <Button className="w-full" onClick={submitCode} disabled={busy}>
                {busy && <LoaderCircle className="animate-spin" />}
                {t('auth.verifyCode')}
              </Button>
              <Button
                variant="ghost"
                className="w-full"
                onClick={sendCode}
                disabled={busy}
              >
                {t('auth.resendCode')}
              </Button>
            </div>
          )}

          {step === 'name' && (
            <div className="flex flex-col gap-5">
              <div className="flex flex-col gap-2">
                <Label htmlFor="name">{t('auth.nameLabel')}</Label>
                <Input
                  id="name"
                  placeholder={t('auth.namePlaceholder')}
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  onKeyDown={(event) => event.key === 'Enter' && finish()}
                />
              </div>
              <Button className="w-full" onClick={finish} disabled={busy}>
                {busy && <LoaderCircle className="animate-spin" />}
                {t('auth.finishSignup')}
              </Button>
            </div>
          )}

          <p className="text-sm text-muted-foreground text-center">
            <Link href="/discover" className="hover:text-foreground">
              {t('nav.discover')}
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
