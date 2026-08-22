'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { zodResolver } from '@hookform/resolvers/zod';
import { ApiError } from '@manfaa/api-client';
import { BrandMark } from '@manfaa/ui';
import { Eye, EyeOff, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { isOnboardingStatus } from '@/lib/api';
import { useLogin } from '@/lib/queries';
import { readReturnTo } from '@/lib/return-to';
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
import { LanguageSwitcher } from '@/components/app/language-switcher';
import { PitchPanel } from '@/components/marketing/pitch';

export default function LoginPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const loginMutation = useLogin();
  const [showPassword, setShowPassword] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const LoginFormSchema = z.object({
    email: z.email(t('auth.emailInvalid')),
    password: z.string().min(1, t('auth.passwordRequired')),
  });
  type LoginFormValues = z.infer<typeof LoginFormSchema>;

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(LoginFormSchema),
    defaultValues: { email: '', password: '' },
  });

  const onSubmit = (values: LoginFormValues) => {
    setErrorMessage(null);
    loginMutation.mutate(values, {
      onSuccess: (me) => {
        // Draft / pending_review / rejected stores land on /setup — the
        // wizard, waiting screen or rejection screen; the panel stays locked.
        if (isOnboardingStatus(me.merchant.status)) {
          router.replace('/setup');
          return;
        }

        // Back to whatever sent them here — a connect consent screen, most
        // often — and the dashboard when nothing did.
        router.replace(readReturnTo() ?? '/dashboard');
      },
      onError: (error) => {
        if (error instanceof ApiError && error.status === 422) {
          setErrorMessage(t('auth.invalidCredentials'));
        } else if (error instanceof ApiError && error.status === 429) {
          setErrorMessage(t('common.tooManyAttempts'));
        } else {
          setErrorMessage(t('common.serverUnreachable'));
        }
      },
    });
  };

  return (
    // Split layout (PLAN §WL): the landing's own pitch panel rides beside
    // the form ≥lg, so a bookmarked login still tells the brand story;
    // below lg the form stands alone exactly as before.
    <div className="grid min-h-screen w-full lg:grid-cols-2">
      <PitchPanel />
      <div className="relative flex grow items-center justify-center bg-muted/40 p-5">
        <div className="absolute top-4 end-4">
          <LanguageSwitcher />
        </div>
        <Card className="w-full max-w-[400px]">
          <CardContent className="p-8 flex flex-col gap-6">
            <div className="flex flex-col items-center gap-3">
              <span className="flex items-center gap-2">
                {/* The platform mark, superadmin-replaceable. "Merchant"
                    stays as the product suffix it has always been. */}
                <BrandMark className="h-[22px] w-auto" alt="Manfaa" />
                <span className="text-lg font-semibold text-violet-600 dark:text-violet-400">
                  {t('marketing.wordmark')}
                </span>
              </span>
              <div className="text-center">
                <h1 className="text-lg font-semibold text-mono">
                  {t('auth.loginTitle')}
                </h1>
                <p className="text-sm text-muted-foreground">
                  {t('auth.loginSubtitle')}
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

            <Form {...form}>
              <form
                onSubmit={form.handleSubmit(onSubmit)}
                className="flex flex-col gap-5"
              >
                <FormField
                  control={form.control}
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
                  control={form.control}
                  name="password"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('auth.passwordLabel')}</FormLabel>
                      <FormControl>
                        <div className="relative">
                          <Input
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="current-password"
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
                <Button
                  type="submit"
                  className="w-full"
                  disabled={loginMutation.isPending}
                >
                  {loginMutation.isPending && (
                    <LoaderCircle className="animate-spin" />
                  )}
                  {t('auth.signIn')}
                </Button>
              </form>
            </Form>

            <p className="text-sm text-muted-foreground text-center">
              {t('auth.noStoreYet')}{' '}
              <Link
                href="/signup"
                className="text-primary font-medium hover:underline"
              >
                {t('auth.createYourStore')}
              </Link>
            </p>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
