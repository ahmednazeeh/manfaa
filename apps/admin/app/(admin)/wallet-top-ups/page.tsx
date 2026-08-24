'use client';

import { useEffect, useState } from 'react';
import {
  getTransferSettings,
  listWalletTopUps,
  WalletTopUpStateSchema,
  type WalletTopUp,
  type WalletTopUpState,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { Paperclip, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { matchRuleLabel } from '@/lib/labels';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { BankLabel } from '@/components/admin/bank-select';
import { PageHeader } from '@/components/admin/page-header';
import { Pager } from '@/components/admin/pager';
import { TopUpStateBadge } from '@/components/admin/state-badge';
import { autoVerifyStatus } from '@/components/wallet-top-ups/auto-verify';
import { AutoVerifyBadge } from '@/components/wallet-top-ups/auto-verify-badge';
import {
  TopUpReviewSheet,
  WALLET_TOP_UPS_QUERY_KEY,
} from '@/components/wallet-top-ups/top-up-review-sheet';

type StateFilter = WalletTopUpState | 'all';

const TABS: { value: StateFilter; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'matched', label: 'Matched' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'all', label: 'All' },
];

const DESCRIPTIONS: Record<StateFilter, string> = {
  pending:
    'Merchants who transferred to a platform account to fund their wallet and uploaded the slip. The bank-history verifier credits what it can find; what it could not is here for a person. Open a row to read the slip, then Match to credit the wallet or Reject with a reason.',
  matched:
    'Credited claims — automatically by the verifier, or by an admin here. The bank’s own reference sits beside what the merchant typed.',
  rejected:
    'Refused claims. Nothing was credited; the bank reference was released so the merchant can claim the transfer again with a corrected slip.',
  all: 'Every wallet top-up claim, newest first.',
};

/**
 * The wallet top-up queue (owner, 2026-08-24): the sibling of the
 * settlement matching queue, for money a merchant sends AHEAD of settlement.
 * Same receipt-first discipline — a claim is not balance until the transfer
 * is found in the bank's history or an admin signs for it.
 *
 * Any admin works this queue; the top-up minimum (Settings › Platform) is
 * the only superadmin lever in the feature.
 */
export default function WalletTopUpsPage() {
  const [state, setState] = useState<StateFilter>('pending');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<WalletTopUp | null>(null);

  // A stable "now" for the watch-window chips; re-read once a minute so a
  // row on screen flips from "watching" to "not found" without a reload.
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 60_000);
    return () => window.clearInterval(timer);
  }, []);

  const query = useQuery({
    // Shares its key with the nav badge on (pending, 1), so opening the page
    // costs nothing extra and the badge never disagrees with the table.
    queryKey: [...WALLET_TOP_UPS_QUERY_KEY, state, page],
    queryFn: ({ signal }) =>
      listWalletTopUps(
        { state: state === 'all' ? undefined : state, page },
        { signal },
      ),
  });

  // The auto-verify verdict needs the watch window and which of our accounts
  // are watched — both live in the transfer settings, read once here.
  const transfer = useQuery({
    queryKey: ['admin', 'transfer-settings'],
    queryFn: ({ signal }) => getTransferSettings({ signal }),
    staleTime: 60_000,
  });
  const settings = transfer.data?.data;

  const onTabChange = (value: string) => {
    const parsed = WalletTopUpStateSchema.safeParse(value);
    setState(parsed.success ? parsed.data : 'all');
    setPage(1);
  };

  const headings =
    state === 'pending'
      ? [
          'Merchant',
          'Amount',
          'Paid into',
          'Bank ref',
          'Slip',
          'Auto-verify',
          'Submitted',
        ]
      : state === 'matched'
        ? [
            'Merchant',
            'Amount',
            'Paid into',
            'Bank reference',
            'How',
            'Submitted',
            'Matched',
          ]
        : state === 'rejected'
          ? [
              'Merchant',
              'Amount',
              'Bank ref',
              'Reason',
              'Submitted',
              'Rejected',
            ]
          : [
              'Merchant',
              'Amount',
              'State',
              'Paid into',
              'Bank ref',
              'Submitted',
            ];

  const endAligned = new Set(['Amount']);

  const merchantCell = (topUp: WalletTopUp) => (
    <TableCell className="font-medium">
      <span className="flex flex-col">
        <span>{topUp.merchant?.name ?? `Merchant #${topUp.merchant_id}`}</span>
        {topUp.merchant?.bank_account_name ? (
          <span className="text-xs font-normal text-muted-foreground">
            pays as {topUp.merchant.bank_account_name}
          </span>
        ) : null}
      </span>
    </TableCell>
  );

  const paidIntoCell = (topUp: WalletTopUp) => (
    <TableCell>
      {topUp.platform_bank_account ? (
        <span className="flex flex-col">
          <BankLabel
            bank={topUp.platform_bank_account.bank_name}
            className="text-sm"
          />
          <span className="text-xs text-muted-foreground" dir="ltr">
            {topUp.platform_bank_account.account_no}
          </span>
        </span>
      ) : (
        <span className="text-muted-foreground">—</span>
      )}
    </TableCell>
  );

  const slipCell = (topUp: WalletTopUp) => (
    <TableCell>
      {topUp.has_slip ? (
        <Badge variant="info" appearance="light" size="sm">
          <Paperclip className="size-3" />
          Attached
        </Badge>
      ) : (
        <span className="text-muted-foreground">None</span>
      )}
    </TableCell>
  );

  const renderCells = (topUp: WalletTopUp) => {
    if (state === 'pending') {
      return (
        <>
          {merchantCell(topUp)}
          <TableCell className="text-end font-medium">
            <MoneyText laari={topUp.amount_laari} />
          </TableCell>
          {paidIntoCell(topUp)}
          <TableCell className="font-mono text-xs">
            {topUp.bank_ref ?? '—'}
          </TableCell>
          {slipCell(topUp)}
          <TableCell>
            <AutoVerifyBadge status={autoVerifyStatus(topUp, settings, now)} />
          </TableCell>
          <TableCell>{formatDateTime(topUp.created_at)}</TableCell>
        </>
      );
    }

    if (state === 'matched') {
      const rule = matchRuleLabel(topUp.matched_by_rule);
      return (
        <>
          {merchantCell(topUp)}
          <TableCell className="text-end font-medium">
            <MoneyText laari={topUp.amount_laari} />
          </TableCell>
          {paidIntoCell(topUp)}
          <TableCell className="font-mono text-xs">
            {/* The bank's own reference once matched, else what the
                merchant typed — the same reading as the settlement queue. */}
            {topUp.matched_trx_id ?? topUp.bank_ref ?? '—'}
            {topUp.matched_payer_name ? (
              <p className="mt-1 font-sans text-xs text-muted-foreground">
                from {topUp.matched_payer_name}
              </p>
            ) : null}
          </TableCell>
          <TableCell>
            <AutoVerifyBadge status={autoVerifyStatus(topUp, settings, now)} />
            {topUp.auto_matched && rule ? (
              <p className="mt-1 text-xs text-muted-foreground">
                by the {rule}
              </p>
            ) : null}
          </TableCell>
          <TableCell>{formatDateTime(topUp.created_at)}</TableCell>
          <TableCell>{formatDateTime(topUp.matched_at)}</TableCell>
        </>
      );
    }

    if (state === 'rejected') {
      return (
        <>
          {merchantCell(topUp)}
          <TableCell className="text-end font-medium">
            <MoneyText laari={topUp.amount_laari} />
          </TableCell>
          <TableCell className="font-mono text-xs">
            {topUp.bank_ref ?? '—'}
          </TableCell>
          <TableCell className="max-w-md">
            <span
              className="line-clamp-2 text-muted-foreground"
              title={topUp.rejected_reason ?? undefined}
            >
              {topUp.rejected_reason ?? '—'}
            </span>
          </TableCell>
          <TableCell>{formatDateTime(topUp.created_at)}</TableCell>
          <TableCell>{formatDateTime(topUp.rejected_at)}</TableCell>
        </>
      );
    }

    return (
      <>
        {merchantCell(topUp)}
        <TableCell className="text-end font-medium">
          <MoneyText laari={topUp.amount_laari} />
        </TableCell>
        <TableCell>
          <TopUpStateBadge state={topUp.state} />
        </TableCell>
        {paidIntoCell(topUp)}
        <TableCell className="font-mono text-xs">
          {topUp.matched_trx_id ?? topUp.bank_ref ?? '—'}
        </TableCell>
        <TableCell>{formatDateTime(topUp.created_at)}</TableCell>
      </>
    );
  };

  return (
    <div className="flex flex-col">
      <PageHeader title="Wallet top-ups" description={DESCRIPTIONS[state]} />

      {settings && !settings.auto_verify_enabled ? (
        <Alert variant="info" appearance="light" size="sm" className="mb-4">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>
            Automatic verification is off, so every claim here waits for a
            person. Turn it on in Settings → Transfer API once a profile watches
            the platform accounts.
          </AlertDescription>
        </Alert>
      ) : null}

      <Tabs value={state} onValueChange={onTabChange} className="mb-4">
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

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
                    {headings.map((heading) => (
                      <TableHead
                        key={heading}
                        className={endAligned.has(heading) ? 'text-end' : ''}
                      >
                        {heading}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 5 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={headings.length}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : query.data.data.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={headings.length}
                        className="py-10 text-center text-muted-foreground"
                      >
                        {state === 'pending'
                          ? 'No top-ups waiting on a decision.'
                          : state === 'all'
                            ? 'No wallet top-ups yet.'
                            : `No ${state} top-ups.`}
                      </TableCell>
                    </TableRow>
                  ) : (
                    query.data.data.map((topUp) => (
                      <TableRow
                        key={topUp.id}
                        className="cursor-pointer"
                        onClick={() => setSelected(topUp)}
                      >
                        {renderCells(topUp)}
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        </Card>
      )}

      {query.data ? (
        <Pager meta={query.data.meta} onPageChange={setPage} />
      ) : null}

      <TopUpReviewSheet
        topUp={selected}
        settings={settings}
        onClose={() => setSelected(null)}
        onDecided={setSelected}
      />
    </div>
  );
}
