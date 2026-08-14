'use client';

import { useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { parseMvrToLaari, type CustomerClaim } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { LoaderCircle, Plus, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { z } from 'zod';
import { fetchClaimMerchants } from '@/lib/claim-merchants';
import { formatDate } from '@/lib/format';
import { useClaims, useCreateClaim } from '@/lib/queries';
import { Alert, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import { ListPagination } from '@/components/app/list-pagination';
import { ClaimStateChip } from '@/components/app/status-chip';

function isValidMvrAmount(input: string): boolean {
  try {
    return parseMvrToLaari(input) >= 1;
  } catch {
    return false;
  }
}

/** Business-timezone "today" is close enough for a date-input upper bound. */
function todayIso(): string {
  const now = new Date();
  const utc5 = new Date(now.getTime() + 5 * 60 * 60 * 1000);
  return utc5.toISOString().slice(0, 10);
}

function NewClaimDialog() {
  const [open, setOpen] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const createMutation = useCreateClaim();
  const { t } = useTranslation();

  const merchantsQuery = useQuery({
    queryKey: ['claims', 'merchants'],
    queryFn: ({ signal }) => fetchClaimMerchants(signal),
    staleTime: 60_000,
    enabled: open,
  });

  const FormSchema = z.object({
    merchant_slug: z.string().min(1, t('claims.merchantRequired')),
    purchased_at: z
      .string()
      .regex(/^\d{4}-\d{2}-\d{2}$/, t('claims.dateRequired')),
    // Money enters as an MVR string and is converted to integer laari via
    // string decomposition — never through a float.
    amount_mvr: z.string().refine(isValidMvrAmount, t('claims.amountInvalid')),
    receipt_no: z.string().min(1, t('claims.receiptRequired')).max(64),
    note: z.string().max(1000).optional(),
  });
  type FormValues = z.infer<typeof FormSchema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: {
      merchant_slug: '',
      purchased_at: '',
      amount_mvr: '',
      receipt_no: '',
      note: '',
    },
  });

  const onSubmit = (values: FormValues) => {
    setSubmitError(null);
    const merchant = merchantsQuery.data?.find(
      (entry) => entry.slug === values.merchant_slug,
    );
    if (!merchant) {
      setSubmitError(t('claims.merchantRequired'));
      return;
    }
    createMutation.mutate(
      {
        merchant_slug: merchant.slug,
        purchased_at: values.purchased_at,
        amount_laari: parseMvrToLaari(values.amount_mvr),
        receipt_no: values.receipt_no,
        note:
          values.note !== undefined && values.note.trim() !== ''
            ? values.note
            : undefined,
      },
      {
        onSuccess: () => {
          toast.success(t('claims.submitted'), {
            description: t('claims.submittedConditionalNote'),
          });
          form.reset();
          setOpen(false);
        },
        onError: () => {
          setSubmitError(t('claims.submitFailed'));
        },
      },
    );
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        setSubmitError(null);
      }}
    >
      <DialogTrigger asChild>
        <Button>
          <Plus />
          {t('claims.newClaim')}
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t('claims.newClaim')}</DialogTitle>
          <DialogDescription>{t('claims.description')}</DialogDescription>
        </DialogHeader>
        <DialogBody>
          {submitError && (
            <Alert
              variant="destructive"
              appearance="light"
              size="sm"
              className="mb-4"
            >
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertTitle>{submitError}</AlertTitle>
            </Alert>
          )}

          <Form {...form}>
            <form
              onSubmit={form.handleSubmit(onSubmit)}
              className="flex flex-col gap-4"
            >
              <FormField
                control={form.control}
                name="merchant_slug"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('claims.merchantLabel')}</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger aria-label={t('claims.merchantLabel')}>
                          <SelectValue
                            placeholder={t('claims.merchantPlaceholder')}
                          />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {(merchantsQuery.data ?? []).map((merchant) => (
                          <SelectItem key={merchant.slug} value={merchant.slug}>
                            {merchant.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {merchantsQuery.isError && (
                      <p className="text-xs text-destructive">
                        {t('claims.merchantListUnavailable')}
                      </p>
                    )}
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="purchased_at"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('claims.dateLabel')}</FormLabel>
                    <FormControl>
                      <Input type="date" max={todayIso()} {...field} />
                    </FormControl>
                    <p className="text-xs text-muted-foreground">
                      {t('claims.dateHint')}
                    </p>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="amount_mvr"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('claims.amountLabel')}</FormLabel>
                    <FormControl>
                      <Input
                        inputMode="decimal"
                        dir="ltr"
                        placeholder={t('claims.amountPlaceholder')}
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="receipt_no"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('claims.receiptLabel')}</FormLabel>
                    <FormControl>
                      <Input
                        placeholder={t('claims.receiptPlaceholder')}
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="note"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('claims.noteLabel')}</FormLabel>
                    <FormControl>
                      <Textarea
                        rows={3}
                        placeholder={t('claims.notePlaceholder')}
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button
                type="submit"
                className="w-full"
                disabled={createMutation.isPending}
              >
                {createMutation.isPending && (
                  <LoaderCircle className="animate-spin" />
                )}
                {t('claims.submit')}
              </Button>
            </form>
          </Form>
        </DialogBody>
      </DialogContent>
    </Dialog>
  );
}

function ClaimRow({ claim }: { claim: CustomerClaim }) {
  const { t } = useTranslation();

  return (
    <div className="flex flex-wrap items-start justify-between gap-3 py-4">
      <div className="flex flex-col gap-1 min-w-0">
        <span className="text-sm font-medium text-mono">
          {claim.merchant.name}
        </span>
        <span className="text-xs text-muted-foreground">
          {t('claims.purchasedOn', { date: formatDate(claim.purchased_at) })}
          {' · '}
          {t('claims.receiptNo', { receiptNo: claim.receipt_no })}
          {' · '}
          {t('claims.filedOn', { date: formatDate(claim.created_at) })}
        </span>
        {claim.resolution_note !== null && (
          <span className="text-xs text-muted-foreground">
            {claim.resolution_note}
          </span>
        )}
      </div>

      <div className="flex flex-col items-end gap-1 shrink-0">
        <MoneyText
          laari={claim.amount_laari}
          currency={claim.currency}
          className="text-sm font-semibold text-mono"
        />
        <ClaimStateChip state={claim.state} />
      </div>
    </div>
  );
}

export default function ClaimsPage() {
  const [page, setPage] = useState(1);
  const { data, isPending, error } = useClaims(page);
  const { t } = useTranslation();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('claims.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('claims.description')}</ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          <NewClaimDialog />
        </ToolbarActions>
      </Toolbar>

      <div className="pb-10">
        <Card>
          {isPending && <LoadingBlock lines={4} />}
          {!isPending && error && <ErrorBlock error={error} />}

          {data && data.data.length === 0 && (
            <EmptyBlock>{t('claims.empty')}</EmptyBlock>
          )}

          {data && data.data.length > 0 && (
            <>
              <CardContent className="divide-y divide-border py-1">
                {data.data.map((claim) => (
                  <ClaimRow key={claim.id} claim={claim} />
                ))}
              </CardContent>
              <CardFooter className="py-3">
                <ListPagination meta={data.meta} onPageChange={setPage} />
              </CardFooter>
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
