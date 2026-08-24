'use client';

import { useState } from 'react';
import {
  downloadReportExport,
  getReportPreview,
  listAdminMerchants,
  REPORT_KINDS,
  ReportKindSchema,
  reportTooLargeDetail,
  type ReportKind,
} from '@manfaa/api-client';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, Scissors, ShieldAlert, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { businessToday } from '@/lib/format';
import {
  formatCount,
  periodProblem,
  presetPeriod,
  type Period,
  type PeriodPreset,
} from '@/lib/reports';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PageHeader } from '@/components/admin/page-header';
import { useAdminUser } from '@/components/auth/admin-guard';
import { PeriodControl } from '@/components/reports/period-control';
import { ReportPreviewTable } from '@/components/reports/preview-table';
import { ReportSummaryPanel } from '@/components/reports/report-summary';

const ALL_MERCHANTS = 'all';

const TAB_LABELS: Record<ReportKind, string> = {
  cashback: 'Cashback',
  payouts: 'Payouts',
  earnings: 'Earnings',
};

const DESCRIPTIONS: Record<ReportKind, string> = {
  cashback:
    'Every transaction by the date it happened, what the store owed on it, and what was actually collected against it. The collected column is per-line and exact: it sums to the money each settlement received, to the laari.',
  payouts:
    'Every reward paid out in the period, read three ways — by transaction, by payout item, and by batch — plus customer wallet withdrawals. The three sheets state one number.',
  earnings:
    'The money trace, derived from the ledger itself, so it can never disagree with the nightly reconciler. Fee income net of discounts, GST as the liability it is, and every journal line behind them.',
};

export default function ReportsPage() {
  const me = useAdminUser();
  const isSuperadmin = me.role === 'superadmin';

  const [kind, setKind] = useState<ReportKind>('cashback');
  const [preset, setPreset] = useState<PeriodPreset>('this_month');
  const [merchantId, setMerchantId] = useState<string>(ALL_MERCHANTS);

  // Held in state rather than read per render so the window cannot move under
  // the reader mid-choice, and re-read whenever a preset is picked so a panel
  // left open past midnight stops offering yesterday as the newest date.
  const [today, setToday] = useState(businessToday);
  const [period, setPeriod] = useState<Period>(() =>
    presetPeriod('this_month', businessToday()),
  );

  const choosePreset = (next: PeriodPreset) => {
    const current = businessToday();
    setToday(current);
    setPreset(next);

    // Picking Custom deliberately leaves the dates alone: it opens on the
    // window already on screen, so switching to it is a nudge of one end
    // rather than starting over from a blank pair of fields.
    if (next !== 'custom') {
      setPeriod(presetPeriod(next, current));
    }
  };

  const problem = periodProblem(period);
  const merchantFilter =
    merchantId === ALL_MERCHANTS ? undefined : Number(merchantId);

  const params = {
    from: period.from,
    to: period.to,
    merchant_id: merchantFilter,
  };

  const merchants = useQuery({
    queryKey: ['admin', 'merchants'],
    queryFn: ({ signal }) => listAdminMerchants({ signal }),
    select: (response) => response.data,
    enabled: isSuperadmin,
  });

  const query = useQuery({
    queryKey: ['admin', 'reports', kind, period.from, period.to, merchantId],
    queryFn: ({ signal }) => getReportPreview(kind, params, { signal }),
    enabled: isSuperadmin && problem === null,
  });

  // A click, never a render: every export writes a `report_exports` audit row,
  // because this is the endpoint that puts customer codes and the money trace
  // into a file that leaves the building.
  const exportFile = useMutation({
    mutationFn: () => downloadReportExport(kind, params),
    onSuccess: ({ blob, filename }) => {
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      toast.success(`${filename} downloaded.`);
    },
    onError: (error) => {
      const tooLarge = reportTooLargeDetail(error);
      toast.error(tooLarge ? tooLarge.message : apiErrorMessage(error));
    },
  });

  if (!isSuperadmin) {
    // Display only — EnsureSuperadmin 403s both endpoints regardless of what
    // this client renders.
    return (
      <div className="flex flex-col">
        <PageHeader title="Reports" />
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <ShieldAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>Superadmin only</AlertTitle>
            <AlertDescription>
              These reports carry customer codes and the platform&apos;s own
              money trace, so reading them requires the superadmin role. Ask an
              existing superadmin if you need access changed.
            </AlertDescription>
          </AlertContent>
        </Alert>
      </div>
    );
  }

  const tooLarge = query.isError ? reportTooLargeDetail(query.error) : null;

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Reports"
        description={DESCRIPTIONS[kind]}
        actions={
          <>
            <Select
              value={merchantId}
              onValueChange={(value) => setMerchantId(value)}
            >
              <SelectTrigger className="w-56">
                <SelectValue placeholder="All merchants" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_MERCHANTS}>All merchants</SelectItem>
                {(merchants.data ?? []).map((merchant) => (
                  <SelectItem key={merchant.id} value={String(merchant.id)}>
                    {merchant.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Button
              onClick={() => exportFile.mutate()}
              disabled={
                exportFile.isPending ||
                problem !== null ||
                query.isPending ||
                // The preview already hit the row cap, and the export counts
                // the same rows — offering the click would only spend a round
                // trip to be told the same thing.
                tooLarge !== null
              }
            >
              <Download />
              {exportFile.isPending ? 'Building…' : 'Export .xlsx'}
            </Button>
          </>
        }
      />

      <Tabs
        value={kind}
        onValueChange={(value) => {
          const parsed = ReportKindSchema.safeParse(value);
          if (parsed.success) {
            setKind(parsed.data);
          }
        }}
        className="mb-5"
      >
        <TabsList variant="line" className="w-full justify-start">
          {REPORT_KINDS.map((candidate) => (
            <TabsTrigger key={candidate} value={candidate}>
              {TAB_LABELS[candidate]}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <PeriodControl
        preset={preset}
        onPresetChange={choosePreset}
        period={period}
        onCustomChange={setPeriod}
        today={today}
        problem={problem}
        disabled={exportFile.isPending}
      />

      {problem !== null ? (
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>
            {problem} Nothing is fetched until the period is a window the report
            can be built over.
          </AlertDescription>
        </Alert>
      ) : tooLarge !== null ? (
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <Scissors />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>Narrow the period</AlertTitle>
            <AlertDescription>
              This period covers {formatCount(tooLarge.row_count)} rows, over
              the {formatCount(tooLarge.limit)} a report can build in one pass.
              Nothing was built and nothing was recorded — pick a shorter
              window, or one merchant, and try again.
            </AlertDescription>
          </AlertContent>
        </Alert>
      ) : query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : query.isPending ? (
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {Array.from({ length: 4 }).map((_, index) => (
              <Skeleton key={index} className="h-24 w-full" />
            ))}
          </div>
          <Skeleton className="h-32 w-full" />
          <Skeleton className="h-72 w-full" />
        </div>
      ) : (
        <>
          <ReportSummaryPanel
            kind={kind}
            summary={query.data.summary}
            merchantFiltered={merchantFilter !== undefined}
          />

          <div className="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <span>The workbook holds</span>
            {query.data.sheets.map((sheet) => (
              <Badge
                key={sheet.title}
                variant="secondary"
                appearance="light"
                size="sm"
              >
                {sheet.title} · {formatCount(sheet.row_count)}
              </Badge>
            ))}
          </div>

          <ReportPreviewTable
            preview={query.data.preview}
            rowCount={query.data.row_count}
            capped={query.data.capped}
          />
        </>
      )}
    </div>
  );
}
