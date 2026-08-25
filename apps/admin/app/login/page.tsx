'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { zodResolver } from '@hookform/resolvers/zod';
import { LoginRequestSchema, type LoginRequest } from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { ADMIN_ME_QUERY_KEY, adminLogin } from '@/lib/admin-auth';
import { apiErrorMessage } from '@/lib/api-error';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { ManfaaLogo } from '@/components/shell/manfaa-logo';

export default function LoginPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const form = useForm<LoginRequest>({
    resolver: zodResolver(LoginRequestSchema),
    defaultValues: { email: '', password: '' },
  });

  const login = useMutation({
    mutationFn: (body: LoginRequest) => adminLogin(body),
    onSuccess: (response) => {
      queryClient.setQueryData(ADMIN_ME_QUERY_KEY, response);
      // Signing in lands on the dashboard — the one screen that says what is
      // waiting before you have to guess which queue to open.
      router.replace('/dashboard');
    },
    onError: (error) => {
      setErrorMessage(
        apiErrorMessage(error, 'Login failed. Check your credentials.'),
      );
    },
  });

  const onSubmit = (values: LoginRequest) => {
    setErrorMessage(null);
    login.mutate(values);
  };

  return (
    <div className="flex min-h-screen w-full items-center justify-center bg-muted/40 p-5">
      <Card className="w-full max-w-sm">
        <CardHeader className="flex-col items-start gap-1 pt-6">
          <CardTitle className="flex items-center gap-2 text-lg">
            <ManfaaLogo />
            <span className="font-semibold text-muted-foreground">Admin</span>
          </CardTitle>
          <CardDescription>
            Sign in with your admin account to continue.
          </CardDescription>
        </CardHeader>
        <CardContent className="pb-6">
          <Form {...form}>
            <form
              onSubmit={form.handleSubmit(onSubmit)}
              className="flex flex-col gap-4"
            >
              {errorMessage ? (
                <Alert variant="destructive" appearance="light" size="sm">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertDescription>{errorMessage}</AlertDescription>
                </Alert>
              ) : null}

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
                        placeholder="you@manfaa.mv"
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
                      <Input
                        type="password"
                        autoComplete="current-password"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" disabled={login.isPending}>
                {login.isPending ? 'Signing in…' : 'Sign in'}
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>
    </div>
  );
}
