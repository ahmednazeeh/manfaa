'use client';

import {
  listAdminPlatformBankAccounts,
  updateAdminPlatformBankAccount,
  type PlatformBankAccount,
  type UpdatePlatformBankAccountRequest,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Landmark, Pencil, Plus, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardTable } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/admin/page-header';
import { BankAccountDialog } from '@/components/settings/bank-account-dialog';

const QUERY_KEY = ['admin', 'platform-bank-accounts'] as const;

function useAccountUpdate(successMessage: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      body,
    }: {
      id: number;
      body: UpdatePlatformBankAccountRequest;
    }) => updateAdminPlatformBankAccount(id, body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: QUERY_KEY });
      toast.success(successMessage);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });
}

function SetPrimaryAction({ account }: { account: PlatformBankAccount }) {
  const promote = useAccountUpdate(
    'Primary account changed — settlement instructions now show it.',
  );

  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button variant="outline" size="sm" disabled={promote.isPending}>
          Set primary
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Make this the primary account?</AlertDialogTitle>
          <AlertDialogDescription>
            Merchant settlement instructions will immediately show{' '}
            <span className="font-medium text-foreground">
              {account.bank_name} · {account.account_no}
            </span>
            . The current primary is demoted in the same step — exactly one
            active primary exists at a time.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            onClick={() =>
              promote.mutate({ id: account.id, body: { is_primary: true } })
            }
          >
            Set primary
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

function DeactivateAction({ account }: { account: PlatformBankAccount }) {
  const deactivate = useAccountUpdate('Account deactivated.');
  const isActivePrimary = account.is_primary && account.active;

  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button variant="outline" size="sm" disabled={deactivate.isPending}>
          Deactivate
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Deactivate this account?</AlertDialogTitle>
          <AlertDialogDescription>
            The account stays on record — past settlement instructions that
            referenced it must stay explicable — but it can no longer be shown
            to merchants.
            {isActivePrimary ? (
              <>
                {' '}
                <span className="font-medium text-destructive">
                  This is the primary account:
                </span>{' '}
                deactivating it leaves merchants with no transfer details until
                another account is made primary.
              </>
            ) : null}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            variant="destructive"
            onClick={() =>
              deactivate.mutate({ id: account.id, body: { active: false } })
            }
          >
            Deactivate
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

function ReactivateAction({ account }: { account: PlatformBankAccount }) {
  const reactivate = useAccountUpdate('Account reactivated.');

  return (
    <Button
      variant="outline"
      size="sm"
      disabled={reactivate.isPending}
      onClick={() =>
        reactivate.mutate({ id: account.id, body: { active: true } })
      }
    >
      {reactivate.isPending ? 'Reactivating…' : 'Reactivate'}
    </Button>
  );
}

export default function BankAccountsPage() {
  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: ({ signal }) => listAdminPlatformBankAccounts({ signal }),
  });

  const accounts = query.data?.data ?? [];
  const activePrimary = accounts.find(
    (account) => account.is_primary && account.active,
  );

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Platform bank accounts"
        description="Where merchants send settlement transfers. Accounts deactivate rather than delete, so old instructions stay explicable."
        actions={
          <BankAccountDialog
            trigger={
              <Button>
                <Plus />
                Add account
              </Button>
            }
          />
        }
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <div className="flex flex-col gap-5">
          {query.isPending ? (
            <Skeleton className="h-20 w-full" />
          ) : activePrimary ? (
            <Alert variant="primary" appearance="light">
              <AlertIcon>
                <Landmark />
              </AlertIcon>
              <AlertContent>
                <AlertTitle>
                  Merchants currently see: {activePrimary.bank_name} ·{' '}
                  {activePrimary.account_no} · {activePrimary.account_name}
                </AlertTitle>
                <AlertDescription>
                  This active primary account is embedded in every merchant
                  settlement instruction ({activePrimary.currency}).
                </AlertDescription>
              </AlertContent>
            </Alert>
          ) : (
            <Alert variant="warning" appearance="light">
              <AlertIcon>
                <TriangleAlert />
              </AlertIcon>
              <AlertContent>
                <AlertTitle>No active primary account</AlertTitle>
                <AlertDescription>
                  Merchant settlement instructions show “needs configuration”
                  until an active account is made primary. Details are never
                  invented.
                </AlertDescription>
              </AlertContent>
            </Alert>
          )}

          <Card>
            <CardTable>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Bank</TableHead>
                      <TableHead>Account</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Added</TableHead>
                      <TableHead className="text-end">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {query.isPending ? (
                      Array.from({ length: 3 }).map((_, index) => (
                        <TableRow key={index}>
                          <TableCell colSpan={5}>
                            <Skeleton className="h-6 w-full" />
                          </TableCell>
                        </TableRow>
                      ))
                    ) : accounts.length === 0 ? (
                      <TableRow>
                        <TableCell
                          colSpan={5}
                          className="py-10 text-center text-muted-foreground"
                        >
                          No bank accounts yet. Add the account merchants should
                          transfer settlements to.
                        </TableCell>
                      </TableRow>
                    ) : (
                      accounts.map((account) => (
                        <TableRow key={account.id}>
                          <TableCell className="font-medium">
                            {account.bank_name}
                          </TableCell>
                          <TableCell>
                            <div className="flex flex-col">
                              <span>{account.account_no}</span>
                              <span className="text-xs text-muted-foreground">
                                {account.account_name} · {account.currency}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell>
                            <div className="flex flex-wrap items-center gap-1.5">
                              {account.is_primary && account.active ? (
                                <Badge variant="primary" size="sm">
                                  Primary — merchants see this
                                </Badge>
                              ) : null}
                              {account.active ? (
                                <Badge
                                  variant="success"
                                  appearance="light"
                                  size="sm"
                                >
                                  Active
                                </Badge>
                              ) : (
                                <Badge
                                  variant="secondary"
                                  appearance="light"
                                  size="sm"
                                >
                                  Deactivated
                                </Badge>
                              )}
                            </div>
                          </TableCell>
                          <TableCell>
                            {formatDateTime(account.created_at)}
                          </TableCell>
                          <TableCell>
                            <div className="flex flex-wrap items-center justify-end gap-1.5">
                              <BankAccountDialog
                                account={account}
                                trigger={
                                  <Button variant="outline" size="sm">
                                    <Pencil />
                                    Edit
                                  </Button>
                                }
                              />
                              {account.active && !account.is_primary ? (
                                <SetPrimaryAction account={account} />
                              ) : null}
                              {account.active ? (
                                <DeactivateAction account={account} />
                              ) : (
                                <ReactivateAction account={account} />
                              )}
                            </div>
                          </TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </div>
            </CardTable>
          </Card>
        </div>
      )}
    </div>
  );
}
