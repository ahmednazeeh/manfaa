'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { zodResolver } from '@hookform/resolvers/zod';
import { ApiError } from '@manfaa/api-client';
import { Eye, EyeOff, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { toAbsoluteUrl } from '@/lib/helpers';
import { normalizeMaldivesPhone } from '@/lib/phone';
import {
  useSignupRegister,
  useSignupRequestOtp,
  useSignupVerifyOtp,
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
import { LanguageSwitcher } from '@/components/app/language-switcher';

type Step = 'phone' | 'code' | 'details';

/**
 * Store self-signup (§1 decision 2026-08-15): Maldivian mobile → SMS OTP →
 * business name/email/password. Mirrors the customer app's OTP idiom;
 * register creates the DRAFT merchant, logs the owner in and lands on the
 * resumable /setup wizard.
 */
export default function SignupPage() {
  const { t } = useTranslation();
  const router = useRouter();

  const [step, setStep] = useState<Step>('phone');
  const [phone, setPhone] = useState('');
  const [signupToken, setSignupToken] = useState('');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const requestOtpMutation = useSignupRequestOtp();
  const verifyOtpMutation = useSignupVerifyOtp();
  const registerMutation = useSignupRegister();

  // -------------------------------------------------------------------- phone
  const PhoneSchema = z.object({
    phone: z
      .string()
      .refine((value) => normalizeMaldivesPhone(value) !== null, {
        message: t('signup.phoneInvalid'),
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
          setErrorMessage(t('signup.phoneInvalid'));
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
          setSignupToken(response.data.signup_token);
          setStep('details');
        },
        onError: (error) => {
          const keys = validationErrorKeys(error);
          if (keys.includes('otp_attempts_exceeded')) {
            setErrorMessage(t('signup.codeAttemptsExceeded'));
          } else if (keys.includes('otp_invalid')) {
            setErrorMessage(t('signup.codeInvalid'));
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
    business_name: z
      .string()
      .trim()
      .min(2, t('signup.businessNameRequired'))
      .max(120),
    email: z.email(t('auth.emailInvalid')).max(255),
    password: z.string().min(8, t('signup.passwordTooShort')).max(255),
  });
  const detailsForm = useForm<z.infer<typeof DetailsSchema>>({
    resolver: zodResolver(DetailsSchema),
    defaultValues: { business_name: '', email: '', password: '' },
  });
  const [showPassword, setShowPassword] = useState(false);

  const finishSignup = (values: z.infer<typeof DetailsSchema>) => {
    setErrorMessage(null);
    registerMutation.mutate(
      { signup_token: signupToken, ...values },
      {
        onSuccess: () => {
          router.replace('/setup');
        },
        onError: (error) => {
          const keys = validationErrorKeys(error);
          if (keys.includes('signup_token_invalid')) {
            setErrorMessage(t('signup.verificationExpired'));
            setCode('');
            setStep('phone');
          } else if (keys.includes('email_already_registered')) {
            setErrorMessage(t('signup.emailAlreadyRegistered'));
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
    phone: t('signup.stepPhone'),
    code: t('signup.stepCode'),
    details: t('signup.stepDetails'),
  };

  return (
    <div className="relative grow flex items-center justify-center min-h-screen w-full bg-muted/40 p-5">
      <div className="absolute top-4 end-4">
        <LanguageSwitcher />
      </div>
      <Card className="w-full max-w-[440px]">
        <CardContent className="p-8 flex flex-col gap-6">
          <div className="flex flex-col items-center gap-3">
            <img
              src={toAbsoluteUrl('/media/app/default-logo.svg')}
              className="dark:hidden h-[26px]"
              alt={t('common.appName')}
            />
            <img
              src={toAbsoluteUrl('/media/app/default-logo-dark.svg')}
              className="hidden dark:block h-[26px]"
              alt={t('common.appName')}
            />
            <div className="text-center">
              <h1 className="text-lg font-semibold text-mono">
                {t('signup.title')}
              </h1>
              <p className="text-sm text-muted-foreground">
                {t('signup.subtitle')}
              </p>
            </div>
          </div>

          {/* Step indicator */}
          <ol className="flex items-center justify-center gap-2">
            {(['phone', 'code', 'details'] as const).map((s, index) => {
              const stepIndex = ['phone', 'code', 'details'].indexOf(step);
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
                      <FormLabel>{t('signup.phoneLabel')}</FormLabel>
                      <FormControl>
                        <Input
                          type="tel"
                          inputMode="tel"
                          autoComplete="tel"
                          dir="ltr"
                          placeholder={t('signup.phonePlaceholder')}
                          {...field}
                        />
                      </FormControl>
                      <p className="text-xs text-muted-foreground">
                        {t('signup.phoneHint')}
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
                  {t('signup.sendCode')}
                </Button>
              </form>
            </Form>
          )}

          {step === 'code' && (
            <div className="flex flex-col gap-5">
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">
                  {t('signup.stepCode')}
                </span>
                <p className="text-xs text-muted-foreground">
                  {t('signup.codeHint', { phone })}
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
                {t('signup.verifyCode')}
              </Button>
              <Button
                type="button"
                variant="ghost"
                className="w-full"
                disabled={requestOtpMutation.isPending}
                onClick={() => sendCode(phone)}
              >
                {t('signup.resendCode')}
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
                  name="business_name"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('signup.businessNameLabel')}</FormLabel>
                      <FormControl>
                        <Input
                          autoComplete="organization"
                          placeholder={t('signup.businessNamePlaceholder')}
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={detailsForm.control}
                  name="email"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('auth.emailLabel')}</FormLabel>
                      <FormControl>
                        <Input
                          type="email"
                          autoComplete="email"
                          placeholder={t('auth.emailPlaceholder')}
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={detailsForm.control}
                  name="password"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('signup.newPasswordLabel')}</FormLabel>
                      <FormControl>
                        <div className="relative">
                          <Input
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="new-password"
                            placeholder={t('auth.passwordPlaceholder')}
                            {...field}
                          />
                          <Button
                            type="button"
                            variant="ghost"
                            mode="icon"
                            size="sm"
                            aria-label={
                              showPassword
                                ? t('common.hidePassword')
                                : t('common.showPassword')
                            }
                            className="absolute end-1 top-1/2 -translate-y-1/2 h-6 w-6 text-muted-foreground"
                            onClick={() => setShowPassword((value) => !value)}
                          >
                            {showPassword ? (
                              <EyeOff className="size-3.5!" />
                            ) : (
                              <Eye className="size-3.5!" />
                            )}
                          </Button>
                        </div>
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <p className="text-xs text-muted-foreground">
                  {t('signup.afterSignupNote')}
                </p>
                <Button
                  type="submit"
                  className="w-full"
                  disabled={registerMutation.isPending}
                >
                  {registerMutation.isPending && (
                    <LoaderCircle className="animate-spin" />
                  )}
                  {t('signup.createStore')}
                </Button>
              </form>
            </Form>
          )}

          <p className="text-sm text-muted-foreground text-center">
            {t('signup.alreadyHaveAccount')}{' '}
            <Link
              href="/login"
              className="text-primary font-medium hover:underline"
            >
              {t('auth.signIn')}
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
