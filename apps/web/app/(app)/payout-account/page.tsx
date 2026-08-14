'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { Landmark, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { z } from 'zod';
import { maskAccountNo } from '@/lib/format';
import { usePayoutAccount, useSavePayoutAccount } from '@/lib/queries';
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

export default function PayoutAccountPage() {
  const { data: account, isPending, error } = usePayoutAccount();
  const saveMutation = useSavePayoutAccount();
  const [saveError, setSaveError] = useState<string | null>(null);
  const { t } = useTranslation();

  const FormSchema = z.object({
    bank_name: z.string().min(1, t('payout.bankRequired')).max(100),
    account_no: z.string().regex(/^\d{6,32}$/, t('payout.accountNoInvalid')),
    account_name: z.string().min(1, t('payout.accountNameRequired')).max(120),
  });
  type FormValues = z.infer<typeof FormSchema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: { bank_name: '', account_no: '', account_name: '' },
  });

  const onSubmit = (values: FormValues) => {
    setSaveError(null);
    saveMutation.mutate(values, {
      onSuccess: () => {
        toast.success(t('payout.saved'));
        form.reset();
      },
      onError: () => {
        setSaveError(t('payout.saveFailed'));
      },
    });
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
                        {account.bank_name}
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
                            <Input
                              placeholder={t('payout.bankPlaceholder')}
                              {...field}
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

                    <p className="text-xs text-muted-foreground">
                      {t('payout.changeEffectiveNote')}
                    </p>

                    <div>
                      <Button type="submit" disabled={saveMutation.isPending}>
                        {saveMutation.isPending && (
                          <LoaderCircle className="animate-spin" />
                        )}
                        {account.has_payout_account
                          ? t('payout.replaceAccount')
                          : t('payout.saveAccount')}
                      </Button>
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
