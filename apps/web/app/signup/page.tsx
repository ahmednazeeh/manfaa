'use client';

import { Suspense, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { zodResolver } from '@hookform/resolvers/zod';
import { ApiError } from '@manfaa/api-client';
import { BrandMark } from '@manfaa/ui';
import { LoaderCircle, TriangleAlert, UserRoundPlus, X } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { normalizeMaldivesPhone } from '@/lib/format';
import {
  useRegister,
  useRequestOtp,
  useVerifyOtp,
  validationErrorKeys,
} from '@/lib/queries';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from '@/components/ui/input-otp';
import { PayoutAccountStep } from '@/components/app/payout-account-step';
import { PhoneInput } from '@/components/app/phone-input';

type Step = 'phone' | 'code' | 'details' | 'bank';

/**
 * Signup (§10 apps/web): phone -> SMS OTP -> name. PASSWORDLESS since
 * 2026-08-18 (owner decision) — the code the member just proved IS the
 * credential, exactly as the customer app has always worked. The register
 * call logs the session in, so success lands straight on the dashboard.
 *
 * A referral link (/r/{code} or ?ref={code}) pre-fills the referrer's
 * 6-digit code; it rides along silently and goes out with the register
 * call. Attribution is optional and removable right up to that moment —
 * after registration it is immutable, so the chip disappears with it.
 */
function SignupForm() {
  const { t } = useTranslation();
  const router = useRouter();
  const searchParams = useSearchParams();

  const [step, setStep] = useState<Step>('phone');
  const [phone, setPhone] = useState('');
  const [signupToken, setSignupToken] = useState('');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Only a well-formed code survives the URL; anything else is dropped.
  const [referralCode, setReferralCode] = useState<string | null>(() => {
    const ref = searchParams.get('ref');
    return ref !== null && /^\d{6}$/.test(ref) ? ref : null;
  });
  // Typed by hand on the details step when no link carried a code — a
  // friend told the code out loud deserves the same attribution as one
  // who tapped a link. Soft validation, mirroring the app: empty is fine,
  // six digits or the button waits.
  const [typedCode, setTypedCode] = useState('');
  const typedCodeOk = typedCode === '' || /^\d{6}$/.test(typedCode);

  const requestOtpMutation = useRequestOtp();
  const verifyOtpMutation = useVerifyOtp();
  const registerMutation = useRegister();

  // -------------------------------------------------------------------- phone
  const PhoneSchema = z.object({
    phone: z
      .string()
      .refine((value) => normalizeMaldivesPhone(value) !== null, {
        message: t('auth.phoneInvalid'),
      }),
  });
  const phoneForm = useForm<z.infer<typeof PhoneSchema>>({
    resolver: zodResolver(PhoneSchema),
    defaultValues: { phone: '' },
  });

  const sendCode = (rawPhone: string, onSent?: () => void) => {
    const normalized = normalizeMaldivesPhone(rawPhone);
    if (normalized === null) {
      return;
    }
    setErrorMessage(null);
    requestOtpMutation.mutate(normalized, {
      onSuccess: () => {
        setPhone(normalized);
        onSent?.();
      },
      onError: (error) => {
        if (error instanceof ApiError && error.status === 429) {
          setErrorMessage(t('common.tooManyAttempts'));
        } else if (error instanceof ApiError && error.status === 422) {
          setErrorMessage(t('auth.phoneInvalid'));
        } else {
          setErrorMessage(t('common.serverUnreachable'));
        }
      },
    });
  };

  // --------------------------------------------------------------------- code
  const [code, setCode] = useState('');

  const verifyCode = () => {
    if (!/^\d{6}$/.test(code)) {
      return;
    }
    setErrorMessage(null);
    verifyOtpMutation.mutate(
      { phone, code },
      {
        onSuccess: (response) => {
          // The number already has an account (revealed only after OTP
          // proof): stop here with "sign in instead" rather than letting
          // them fill the whole details form and be refused at the end.
          if (response.data.already_registered === true) {
            setErrorMessage(t('auth.phoneAlreadyRegistered'));
            return;
          }
          setSignupToken(response.data.signup_token);
          setStep('details');
        },
        onError: (error) => {
          const keys = validationErrorKeys(error);
          if (keys.includes('otp_attempts_exceeded')) {
            setErrorMessage(t('auth.codeAttemptsExceeded'));
          } else if (keys.includes('otp_invalid')) {
            setErrorMessage(t('auth.codeInvalid'));
          } else if (error instanceof ApiError && error.status === 429) {
            setErrorMessage(t('common.tooManyAttempts'));
          } else {
            setErrorMessage(t('common.serverUnreachable'));
          }
        },
      },
    );
  };

  // ------------------------------------------------------------------ details
  const DetailsSchema = z.object({
    name: z.string().min(1, t('auth.nameRequired')).max(120),
  });
  const detailsForm = useForm<z.infer<typeof DetailsSchema>>({
    resolver: zodResolver(DetailsSchema),
    defaultValues: { name: '' },
  });

  const finishSignup = (values: z.infer<typeof DetailsSchema>) => {
    setErrorMessage(null);
    registerMutation.mutate(
      {
        signup_token: signupToken,
        ...((referralCode ?? (/^\d{6}$/.test(typedCode) ? typedCode : null)) !== null
          ? { referral_code: referralCode ?? typedCode }
          : {}),
        ...values,
      },
      {
        onSuccess: () => {
          // The account exists and the session is live — the dashboard is
          // one skip away. Asking here rather than nudging from the
          // dashboard later is the difference between details we have when
          // the first payout runs and details we chase afterwards.
          setStep('bank');
        },
        onError: (error) => {
          const keys = validationErrorKeys(error);
          if (keys.includes('signup_token_invalid')) {
            setErrorMessage(t('auth.codeExpiredOrInvalidToken'));
            setCode('');
            setStep('phone');
          } else if (keys.includes('phone_already_registered')) {
            setErrorMessage(t('auth.phoneAlreadyRegistered'));
          } else if (error instanceof ApiError && error.status === 429) {
            setErrorMessage(t('common.tooManyAttempts'));
          } else {
            setErrorMessage(t('common.serverUnreachable'));
          }
        },
      },
    );
  };

  const stepTitles: Record<Step, string> = {
    phone: t('auth.stepPhone'),
    code: t('auth.stepCode'),
    details: t('auth.stepDetails'),
    bank: t('auth.stepBank'),
  };

  return (
    <div className="grow flex items-center justify-center min-h-screen w-full bg-muted/40 p-5">
      <Card className="w-full max-w-[420px]">
        <CardContent className="p-8 flex flex-col gap-6">
          <div className="flex flex-col items-center gap-3">
            {/* Square, matching the sign-in card beside it. */}
            <BrandMark
              shape="square"
              className="h-14 w-auto object-contain"
              alt={t('common.appName')}
            />
            <div className="text-center">
              <h1 className="text-lg font-semibold text-mono">
                {t('auth.signupTitle')}
              </h1>
              <p className="text-sm text-muted-foreground">
                {t('auth.signupSubtitleDescription')}
              </p>
            </div>
          </div>

          {/* Step indicator */}
          <ol className="flex items-center justify-center gap-2">
            {(['phone', 'code', 'details', 'bank'] as const).map((s, index) => {
              const stepIndex = ['phone', 'code', 'details', 'bank'].indexOf(
                step,
              );
              const active = index <= stepIndex;
              return (
                <li key={s} className="flex items-center gap-2">
                  <span
                    aria-current={s === step ? 'step' : undefined}
                    className={
                      active
                        ? 'size-6 rounded-full bg-primary text-primary-foreground text-xs font-medium inline-flex items-center justify-center'
                        : 'size-6 rounded-full bg-muted text-muted-foreground text-xs font-medium inline-flex items-center justify-center'
                    }
                  >
                    {index + 1}
                  </span>
                  <span className="sr-only">{stepTitles[s]}</span>
                  {index < 2 && <span className="w-6 h-px bg-border" />}
                </li>
              );
            })}
          </ol>

          {/* Attribution already happened by the bank step — no chip there. */}
          {referralCode !== null && step !== 'bank' && (
            <div className="flex justify-center">
              <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                <UserRoundPlus className="size-3.5 shrink-0" />
                {t('auth.referredByFriendCode', { code: referralCode })}
                <button
                  type="button"
                  aria-label={t('auth.removeReferral')}
                  onClick={() => setReferralCode(null)}
                  className="-me-1 rounded-full p-0.5 transition-colors hover:bg-border hover:text-foreground"
                >
                  <X className="size-3" />
                </button>
              </span>
            </div>
          )}

          {errorMessage && (
            <Alert variant="destructive" appearance="light" size="sm">
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertTitle>{errorMessage}</AlertTitle>
            </Alert>
          )}

          {step === 'phone' && (
            <Form {...phoneForm}>
              <form
                onSubmit={phoneForm.handleSubmit((values) =>
                  sendCode(values.phone, () => setStep('code')),
                )}
                className="flex flex-col gap-5"
              >
                <FormField
                  control={phoneForm.control}
                  name="phone"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('auth.phoneLabel')}</FormLabel>
                      <FormControl>
                        <PhoneInput
                          placeholder={t('auth.phonePlaceholder')}
                          {...field}
                        />
                      </FormControl>
                      <p className="text-xs text-muted-foreground">
                        {t('auth.stepPhoneHint')}
                      </p>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <Button
                  type="submit"
                  className="w-full"
                  disabled={requestOtpMutation.isPending}
                >
                  {requestOtpMutation.isPending && (
                    <LoaderCircle className="animate-spin" />
                  )}
                  {t('auth.sendCode')}
                </Button>
              </form>
            </Form>
          )}

          {step === 'code' && (
            <div className="flex flex-col gap-5">
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">
                  {t('auth.stepCode')}
                </span>
                <p className="text-xs text-muted-foreground">
                  {t('auth.stepCodeHint', { phone })}
                </p>
              </div>
              <div className="flex justify-center" dir="ltr">
                <InputOTP
                  maxLength={6}
                  value={code}
                  onChange={setCode}
                  autoFocus
                >
                  <InputOTPGroup>
                    {[0, 1, 2, 3, 4, 5].map((index) => (
                      <InputOTPSlot key={index} index={index} />
                    ))}
                  </InputOTPGroup>
                </InputOTP>
              </div>
              <Button
                type="button"
                className="w-full"
                disabled={verifyOtpMutation.isPending || !/^\d{6}$/.test(code)}
                onClick={verifyCode}
              >
                {verifyOtpMutation.isPending && (
                  <LoaderCircle className="animate-spin" />
                )}
                {t('auth.verifyCode')}
              </Button>
              <Button
                type="button"
                variant="ghost"
                className="w-full"
                disabled={requestOtpMutation.isPending}
                onClick={() => sendCode(phone)}
              >
                {t('auth.resendCode')}
              </Button>
            </div>
          )}

          {step === 'details' && (
            <Form {...detailsForm}>
              <form
                onSubmit={detailsForm.handleSubmit(finishSignup)}
                className="flex flex-col gap-5"
              >
                <FormField
                  control={detailsForm.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('auth.nameLabel')}</FormLabel>
                      <FormControl>
                        <Input
                          autoComplete="name"
                          placeholder={t('auth.namePlaceholder')}
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                {referralCode === null && (
                  <div className="flex flex-col gap-1.5">
                    <label
                      htmlFor="referral-code"
                      className="text-sm font-medium"
                    >
                      {t('auth.referralCodeOptional')}
                    </label>
                    <Input
                      id="referral-code"
                      dir="ltr"
                      inputMode="numeric"
                      maxLength={6}
                      autoComplete="off"
                      placeholder="000000"
                      value={typedCode}
                      onChange={(event) =>
                        setTypedCode(event.target.value.replace(/\D/g, ''))
                      }
                    />
                    {!typedCodeOk && (
                      <p className="text-xs text-destructive">
                        {t('auth.referralCodeInvalid')}
                      </p>
                    )}
                  </div>
                )}
                <Button
                  type="submit"
                  className="w-full"
                  disabled={registerMutation.isPending || !typedCodeOk}
                >
                  {registerMutation.isPending && (
                    <LoaderCircle className="animate-spin" />
                  )}
                  {t('auth.finishSignup')}
                </Button>
              </form>
            </Form>
          )}

          {step === 'bank' && (
            <PayoutAccountStep onDone={() => router.replace('/dashboard')} />
          )}

          {step !== 'bank' && (
            <p className="text-sm text-muted-foreground text-center">
              {t('auth.alreadyHaveAccount')}{' '}
              <Link
                href="/login"
                className="text-primary font-medium hover:underline"
              >
                {t('auth.signIn')}
              </Link>
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

/**
 * useSearchParams() (the ?ref= referral pre-fill) needs a Suspense
 * boundary above it for the static prerender; the fallback never shows
 * for longer than hydration takes.
 */
export default function SignupPage() {
  return (
    <Suspense fallback={null}>
      <SignupForm />
    </Suspense>
  );
}
