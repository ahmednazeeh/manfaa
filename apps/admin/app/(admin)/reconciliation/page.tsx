'use client';

import { Fragment, useState } from 'react';
import {
  listAdminReconciliationRuns,
  type ReconciliationIssue,
  type ReconciliationRun,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, ChevronRight, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
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
import { ReconciliationStatusBadge } from '@/components/admin/state-badge';

function IssueRow({ issue }: { issue: ReconciliationIssue }) {
  if (issue.kind === 'unbalanced_journal') {
    return (
      <li className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="font-medium">Unbalanced journal</span>
        <span className="text-muted-foreground">
          #{issue.journal_id} ({issue.currency})
        </span>
        <span>
          debits <MoneyText laari={issue.debit_laari} /> vs credits{' '}
          <MoneyText laari={issue.credit_laari} />
        </span>
      </li>
    );
  }
  return (
    <li className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span className="font-medium">Balance mismatch</span>
      <span className="text-muted-foreground">{issue.account}</span>
      <span>
        derived <MoneyText laari={issue.derived_laari} /> vs ledger{' '}
        <MoneyText laari={issue.ledger_laari} />
      </span>
    </li>
  );
}

function RunDetail({ run }: { run: ReconciliationRun }) {
  const issues = run.issues ?? [];
  const totals = Object.entries(run.totals);

  return (
    <div className="flex flex-col gap-4 bg-muted/40 px-6 py-4">
      {issues.length > 0 ? (
        <div>
          <h3 className="mb-2 text-sm font-semibold text-destructive">
            Issues ({issues.length})
          </h3>
          <ul className="flex list-inside list-disc flex-col gap-1 text-sm">
            {issues.map((issue, index) => (
              <IssueRow key={index} issue={issue} />
            ))}
          </ul>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">
          No issues — every journal balances and derived balances match the
          ledger.
        </p>
      )}

      {totals.length > 0 ? (
        <div>
          <h3 className="mb-2 text-sm font-semibold">
            Per-account balances (derived vs ledger)
          </h3>
          <div className="overflow-x-auto rounded-md border border-border bg-background">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-end">Derived</TableHead>
                  <TableHead className="text-end">Ledger</TableHead>
                  <TableHead className="text-end">Difference</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {totals.map(([account, balances]) => {
                  const diff = balances.derived_laari - balances.ledger_laari;
                  return (
                    <TableRow key={account}>
                      <TableCell className="font-medium">{account}</TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={balances.derived_laari} />
                      </TableCell>
                      <TableCell className="text-end">
                        <MoneyText laari={balances.ledger_laari} />
                      </TableCell>
                      <TableCell
                        className={cn(
                          'text-end',
                          diff !== 0 && 'font-semibold text-destructive',
                        )}
                      >
                        <MoneyText laari={diff} />
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        </div>
      ) : null}
    </div>
  );
}

export default function ReconciliationPage() {
  const [expanded, setExpanded] = useState<ReadonlySet<number>>(new Set());

  const query = useQuery({
    queryKey: ['admin', 'reconciliation'],
    queryFn: ({ signal }) => listAdminReconciliationRuns({}, { signal }),
  });

  const toggle = (id: number) => {
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Reconciliation"
        description="Daily runs of the ledger invariant: every journal sums to zero and derived balances match the ledger."
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <Card>
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-10" />
                    <TableHead>Ran at</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-end">Journals checked</TableHead>
                    <TableHead className="text-end">Issues</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 5 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={5}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : query.data.data.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={5}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No reconciliation runs recorded yet.
                      </TableCell>
                    </TableRow>
                  ) : (
                    query.data.data.map((run) => {
                      const isOpen = expanded.has(run.id);
                      const issueCount = run.issues?.length ?? 0;
                      return (
                        <Fragment key={run.id}>
                          <TableRow
                            className="cursor-pointer"
                            onClick={() => toggle(run.id)}
                          >
                            <TableCell>
                              <Button variant="ghost" size="sm" mode="icon">
                                {isOpen ? (
                                  <ChevronDown className="size-4" />
                                ) : (
                                  <ChevronRight className="size-4 rtl:rotate-180" />
                                )}
                              </Button>
                            </TableCell>
                            <TableCell className="font-medium">
                              {formatDateTime(run.ran_at)}
                            </TableCell>
                            <TableCell>
                              <ReconciliationStatusBadge status={run.status} />
                            </TableCell>
                            <TableCell className="text-end">
                              {run.journals_checked}
                            </TableCell>
                            <TableCell
                              className={cn(
                                'text-end',
                                issueCount > 0 &&
                                  'font-semibold text-destructive',
                              )}
                            >
                              {issueCount}
                            </TableCell>
                          </TableRow>
                          {isOpen ? (
                            <TableRow className="hover:bg-transparent">
                              <TableCell colSpan={5} className="p-0">
                                <RunDetail run={run} />
                              </TableCell>
                            </TableRow>
                          ) : null}
                        </Fragment>
                      );
                    })
                  )}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        </Card>
      )}
    </div>
  );
}
