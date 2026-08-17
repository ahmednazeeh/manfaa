'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { apiErrorCode, BankSlugSchema } from '@manfaa/api-client';
import { Landmark, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { z } from 'zod';
import { maskAccountNo } from '@/lib/format';
import {
  usePayoutAccount,
  useRequestPayoutOtp,
  useSavePayoutAccount,
} from '@/lib/queries';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import { BankLabel, BankSelect } from '@/components/app/bank-select';

export default function PayoutAccountPage() {
  const { data: account, isPending, error } = usePayoutAccount();
  const saveMutation = useSavePayoutAccount();
  const otpMutation = useRequestPayoutOtp();
  const [saveError, setSaveError] = useState<string | null>(null);
  // The fresh-OTP gate (same as the app): filling the form is step one;
  // saving requires a code sent to the number on file.
  const [codeSent, setCodeSent] = useState(false);
  const [otpCode, setOtpCode] = useState('');
  const { t } = useTranslation();

  const FormSchema = z.object({
    bank_name: BankSlugSchema,
    account_no: z.string().regex(/^\d{6,32}$/, t('payout.accountNoInvalid')),
    account_name: z.string().min(1, t('payout.accountNameRequired')).max(120),
  });
  type FormValues = z.infer<typeof FormSchema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: { account_no: '', account_name: '' },
  });

  const sendCode = () => {
    setSaveError(null);
    otpMutation.mutate(undefined, {
      onSuccess: () => {
        setCodeSent(true);
        toast.success(t('payout.otpSent'));
      },
      onError: () => setSaveError(t('payout.otpSendFailed')),
    });
  };

  const onSubmit = (values: FormValues) => {
    setSaveError(null);

    // Step one: valid details but no code yet → send the code.
    if (!codeSent) {
      sendCode();
      return;
    }

    if (!/^\d{6}$/.test(otpCode)) {
      setSaveError(t('payout.otpInvalid'));
      return;
    }

    saveMutation.mutate(
      { ...values, otp_code: otpCode },
      {
        onSuccess: () => {
          toast.success(t('payout.saved'));
          form.reset();
          setCodeSent(false);
          setOtpCode('');
        },
        onError: (mutationError) => {
          const code = apiErrorCode(mutationError);
          setSaveError(
            code === 'otp_invalid' || code === 'otp_attempts_exceeded'
              ? t('payout.otpInvalid')
              : t('payout.saveFailed'),
          );
        },
      },
    );
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('payout.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('payout.description')}</ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      <div className="flex flex-col gap-5 max-w-xl pb-10">
        {isPending && <LoadingBlock lines={3} />}
        {!isPending && error && <ErrorBlock error={error} />}

        {account && (
          <>
            <Card>
              <CardHeader>
                <CardTitle>{t('payout.currentAccount')}</CardTitle>
              </CardHeader>
              <CardContent className="p-5">
                {account.has_payout_account && account.account_no !== null ? (
                  <div className="flex items-center gap-4">
                    <span className="size-10 rounded-full bg-muted inline-flex items-center justify-center shrink-0">
                      <Landmark className="size-5 text-muted-foreground" />
                    </span>
                    <div className="flex flex-col gap-0.5 min-w-0">
                      <span className="text-sm font-medium text-mono">
                        <BankLabel bank={account.bank_name} />
                      </span>
                      <span
                        className="text-sm text-secondary-foreground tabular-nums"
                        dir="ltr"
                      >
                        {maskAccountNo(account.account_no)}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {account.account_name}
                      </span>
                    </div>
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    {t('payout.noAccountYet')}
                  </p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>
                  {account.has_payout_account
                    ? t('payout.replaceAccount')
                    : t('payout.saveAccount')}
                </CardTitle>
              </CardHeader>
              <CardContent className="p-5 flex flex-col gap-5">
                {saveError && (
                  <Alert variant="destructive" appearance="light" size="sm">
                    <AlertIcon>
                      <TriangleAlert />
                    </AlertIcon>
                    <AlertTitle>{saveError}</AlertTitle>
                  </Alert>
                )}

                <Form {...form}>
                  <form
                    onSubmit={form.handleSubmit(onSubmit)}
                    className="flex flex-col gap-5"
                  >
                    <FormField
                      control={form.control}
                      name="bank_name"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>{t('payout.bankLabel')}</FormLabel>
                          <FormControl>
                            <BankSelect
                              value={field.value}
                              onChange={field.onChange}
                              placeholder={t('payout.bankPlaceholder')}
                            />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="account_no"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>{t('payout.accountNoLabel')}</FormLabel>
                          <FormControl>
                            <Input
                              inputMode="numeric"
                              dir="ltr"
                              placeholder={t('payout.accountNoPlaceholder')}
                              {...field}
                            />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="account_name"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>{t('payout.accountNameLabel')}</FormLabel>
                          <FormControl>
                            <Input
                              placeholder={t('payout.accountNamePlaceholder')}
                              {...field}
                            />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />

                    {codeSent && (
                      <FormItem>
                        <FormLabel>{t('payout.otpLabel')}</FormLabel>
                        <FormControl>
                          <Input
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            dir="ltr"
                            maxLength={6}
                            placeholder="••••••"
                            value={otpCode}
                            onChange={(event) =>
                              setOtpCode(event.target.value.replace(/\D/g, ''))
                            }
                          />
                        </FormControl>
                        <p className="text-xs text-muted-foreground">
                          {t('payout.otpSentNote')}
                        </p>
                      </FormItem>
                    )}

                    <p className="text-xs text-muted-foreground">
                      {t('payout.changeEffectiveNote')}
                    </p>

                    <div className="flex items-center gap-3">
                      <Button
                        type="submit"
                        disabled={
                          saveMutation.isPending || otpMutation.isPending
                        }
                      >
                        {(saveMutation.isPending || otpMutation.isPending) && (
                          <LoaderCircle className="animate-spin" />
                        )}
                        {codeSent
                          ? account.has_payout_account
                            ? t('payout.replaceAccount')
                            : t('payout.saveAccount')
                          : t('payout.sendCode')}
                      </Button>
                      {codeSent && (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          disabled={otpMutation.isPending}
                          onClick={sendCode}
                        >
                          {t('payout.resendCode')}
                        </Button>
                      )}
                    </div>
                  </form>
                </Form>
              </CardContent>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
