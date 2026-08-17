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
import { normalizeMaldivesPhone } from '@/lib/format';
import { toAbsoluteUrl } from '@/lib/helpers';
import { useLogin } from '@/lib/queries';
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
import { PhoneInput } from '@/components/app/phone-input';

export default function LoginPage() {
  const router = useRouter();
  const loginMutation = useLogin();
  const [showPassword, setShowPassword] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const { t } = useTranslation();

  const LoginFormSchema = z.object({
    phone: z
      .string()
      .refine((value) => normalizeMaldivesPhone(value) !== null, {
        message: t('auth.phoneInvalid'),
      }),
    password: z.string().min(1, t('auth.passwordRequired')),
  });
  type LoginFormValues = z.infer<typeof LoginFormSchema>;

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(LoginFormSchema),
    defaultValues: { phone: '', password: '' },
  });

  const onSubmit = (values: LoginFormValues) => {
    setErrorMessage(null);
    loginMutation.mutate(
      {
        phone: normalizeMaldivesPhone(values.phone) ?? values.phone,
        password: values.password,
      },
      {
        onSuccess: () => {
          router.replace('/dashboard');
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
      },
    );
  };

  return (
    <div className="grow flex items-center justify-center min-h-screen w-full bg-muted/40 p-5">
      <Card className="w-full max-w-[400px]">
        <CardContent className="p-8 flex flex-col gap-6">
          <div className="flex flex-col items-center gap-3">
            <img
              src={toAbsoluteUrl('/media/app/default-logo.svg?v=mf2')}
              className="dark:hidden h-[26px]"
              alt={t('common.appName')}
            />
            <img
              src={toAbsoluteUrl('/media/app/default-logo-dark.svg?v=mf2')}
              className="hidden dark:block h-[26px]"
              alt={t('common.appName')}
            />
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
            {t('auth.noAccountYet')}{' '}
            <Link
              href="/signup"
              className="text-primary font-medium hover:underline"
            >
              {t('auth.createAccount')}
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
