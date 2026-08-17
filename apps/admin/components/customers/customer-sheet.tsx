'use client';

import { ReactNode, useState } from 'react';
import {
  getAdminCustomer,
  type AdminCustomerDetail,
  type AdminCustomerRow,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { Eye, TriangleAlert } from 'lucide-react';
import { useAdminUser } from '@/components/auth/admin-guard';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import {
  Avatar,
  AvatarFallback,
  AvatarImage,
} from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { CustomerStatusBadge } from '@/components/admin/state-badge';
import { CustomerAccountActions } from '@/components/customers/customer-account-actions';
import { EditCustomerDialog } from '@/components/customers/edit-customer-dialog';

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {label}
      </span>
      <div className="text-sm text-foreground">{children}</div>
    </div>
  );
}

function Empty({ children = 'Not provided' }: { children?: ReactNode }) {
  return <span className="text-muted-foreground italic">{children}</span>;
}

function SectionTitle({ children }: { children: ReactNode }) {
  return <h3 className="text-sm font-semibold text-foreground">{children}</h3>;
}

/**
 * The full support record for one customer, opened from their list row:
 * profile, the MASKED payout account, the balance the customer themselves
 * sees, and the live app device count — with the superadmin rescue actions
 * (edit incl. phone change, web password reset, enable/disable) beside the
 * data they act on. Plain admins see the same record read-only; the server
 * 403s the writes regardless of what this client renders.
 */
export function CustomerSheet({ customer }: { customer: AdminCustomerRow }) {
  const [open, setOpen] = useState(false);
  const me = useAdminUser();
  const isSuperadmin = me.role === 'superadmin';

  const query = useQuery({
    queryKey: ['admin', 'customer-detail', customer.id],
    queryFn: ({ signal }) => getAdminCustomer(customer.id, { signal }),
    enabled: open,
  });

  const detail: AdminCustomerDetail | undefined = query.data?.data;

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm">
          <Eye />
          View
        </Button>
      </SheetTrigger>
      <SheetContent className="sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>{customer.name}</SheetTitle>
          <SheetDescription>
            Profile, payout account, balance and devices —
            {isSuperadmin
              ? ' with the superadmin account actions beside what they act on.'
              : ' read-only; account changes require the superadmin role.'}
          </SheetDescription>
        </SheetHeader>
        <SheetBody className="grow p-0">
          <ScrollArea className="h-full px-5 py-4">
            {query.isPending ? (
              <div className="flex flex-col gap-3">
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-32 w-full" />
                <Skeleton className="h-24 w-full" />
              </div>
            ) : query.isError ? (
              <Alert variant="destructive" appearance="light" size="sm">
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertDescription>
                  {apiErrorMessage(query.error)}
                </AlertDescription>
              </Alert>
            ) : detail !== undefined ? (
              <div className="flex flex-col gap-5 pb-4">
                <div className="flex items-start gap-4">
                  <Avatar className="size-14">
                    {detail.avatar_url !== null ? (
                      <AvatarImage src={detail.avatar_url} alt={detail.name} />
                    ) : null}
                    <AvatarFallback>
                      {detail.name.trim().charAt(0).toUpperCase() || '?'}
                    </AvatarFallback>
                  </Avatar>
                  <div className="flex min-w-0 flex-col gap-1.5">
                    <span className="text-base font-semibold">
                      {detail.name}
                    </span>
                    <span dir="ltr" className="font-mono text-sm text-muted-foreground">
                      {detail.phone}
                    </span>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <CustomerStatusBadge status={detail.status} />
                      <Badge variant="secondary" appearance="light" size="sm">
                        Code {detail.customer_code}
                      </Badge>
                    </div>
                  </div>
                </div>

                {isSuperadmin ? (
                  <div className="flex flex-wrap items-center gap-2">
                    <EditCustomerDialog customer={detail} />
                    <CustomerAccountActions customer={detail} />
                  </div>
                ) : null}

                <Separator />

                <div className="grid grid-cols-2 gap-4">
                  <Field label="Email">{detail.email ?? <Empty />}</Field>
                  <Field label="KYC">{detail.kyc_status}</Field>
                  <Field label="Phone verified">
                    {detail.phone_verified_at !== null ? (
                      formatDateTime(detail.phone_verified_at)
                    ) : (
                      <Empty>Not verified</Empty>
                    )}
                  </Field>
                  <Field label="Joined">
                    {formatDateTime(detail.created_at)}
                  </Field>
                  <Field label="App devices signed in">
                    {detail.devices_count}
                  </Field>
                </div>

                <Separator />

                <div className="flex flex-col gap-3">
                  <SectionTitle>Balance</SectionTitle>
                  <div className="grid grid-cols-2 gap-4">
                    <Field label="Confirmed">
                      <MoneyText laari={detail.balance.confirmed_laari} />
                    </Field>
                    <Field label="Pending">
                      <MoneyText laari={detail.balance.pending_laari} />
                    </Field>
                    <Field label="Paid this month">
                      <MoneyText laari={detail.balance.paid_this_month_laari} />
                    </Field>
                  </div>
                </div>

                <Separator />

                <div className="flex flex-col gap-3">
                  <SectionTitle>Payout account</SectionTitle>
                  {detail.payout_account !== null ? (
                    <div className="grid grid-cols-2 gap-4">
                      <Field label="Bank">
                        <span className="uppercase">
                          {detail.payout_account.bank}
                        </span>
                      </Field>
                      <Field label="Account">
                        <span dir="ltr" className="font-mono">
                          {detail.payout_account.account_masked ?? <Empty />}
                        </span>
                      </Field>
                      <Field label="Account name">
                        {detail.payout_account.account_name}
                      </Field>
                    </div>
                  ) : (
                    <p className="text-sm text-muted-foreground">
                      None on file — payout batches skip this customer until
                      they add one in the app.
                    </p>
                  )}
                  <p className="text-xs text-muted-foreground">
                    Shown masked on purpose — support confirms the last
                    digits, never reads the full number. Only the customer can
                    change it (fresh OTP required).
                  </p>
                </div>
              </div>
            ) : null}
          </ScrollArea>
        </SheetBody>
      </SheetContent>
    </Sheet>
  );
}
