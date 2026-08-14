'use client';

import { useState } from 'react';
import { ApiError } from '@manfaa/api-client';
import { zodResolver } from '@hookform/resolvers/zod';
import { Eye, EyeOff, LoaderCircle, TriangleAlert } from 'lucide-react';
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
import { useForm } from 'react-hook-form';
import { useRouter } from 'next/navigation';
import { z } from 'zod';

const LoginFormSchema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
});
type LoginFormValues = z.infer<typeof LoginFormSchema>;

export default function LoginPage() {
  const router = useRouter();
  const loginMutation = useLogin();
  const [showPassword, setShowPassword] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(LoginFormSchema),
    defaultValues: { email: '', password: '' },
  });

  const onSubmit = (values: LoginFormValues) => {
    setErrorMessage(null);
    loginMutation.mutate(values, {
      onSuccess: () => {
        router.replace('/dashboard');
      },
      onError: (error) => {
        if (error instanceof ApiError && error.status === 422) {
          setErrorMessage('Invalid email or password.');
        } else if (error instanceof ApiError && error.status === 429) {
          setErrorMessage('Too many attempts — try again in a minute.');
        } else {
          setErrorMessage('Could not reach the server. Try again.');
        }
      },
    });
  };

  return (
    <div className="grow flex items-center justify-center min-h-screen w-full bg-muted/40 p-5">
      <Card className="w-full max-w-[400px]">
        <CardContent className="p-8 flex flex-col gap-6">
          <div className="flex flex-col items-center gap-3">
            <img
              src={toAbsoluteUrl('/media/app/default-logo.svg')}
              className="dark:hidden h-[26px]"
              alt="Manfaa"
            />
            <img
              src={toAbsoluteUrl('/media/app/default-logo-dark.svg')}
              className="hidden dark:block h-[26px]"
              alt="Manfaa"
            />
            <div className="text-center">
              <h1 className="text-lg font-semibold text-mono">
                Merchant panel
              </h1>
              <p className="text-sm text-muted-foreground">
                Sign in with your merchant account
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
                    <FormLabel>Email</FormLabel>
                    <FormControl>
                      <Input
                        type="email"
                        autoComplete="email"
                        placeholder="you@store.mv"
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
                    <FormLabel>Password</FormLabel>
                    <FormControl>
                      <div className="relative">
                        <Input
                          type={showPassword ? 'text' : 'password'}
                          autoComplete="current-password"
                          placeholder="Your password"
                          {...field}
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          mode="icon"
                          size="sm"
                          aria-label={
                            showPassword ? 'Hide password' : 'Show password'
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
                Sign in
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>
    </div>
  );
}
