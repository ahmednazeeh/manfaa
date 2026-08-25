'use client';

import { useEffect, useMemo, useState } from 'react';
import {
  type SettlementPickerBuckets,
  type SettlementPickerRow,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { format } from 'date-fns';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { anyGst } from '@/lib/gst';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardTable } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

/**
 * The settlement picker: every payable sale the merchant can settle right
 * now, with checkboxes, age filters and a running total.
 *
 * **All of the money here is the server's.** Each row carries `due_laari` —
 * the sum of its stored cashback, fee and GST integers — and each filter chip
 * carries its bucket's count and total from the same response. The panel adds
 * exact laari integers to keep a checkbox click instant; it never applies a
 * rate, never divides, and never derives what a merchant owes. The amount
 * actually transferred comes from the priced preview on the next step.
 *
 * **The age rules are the server's too.** `age_days` and `overdue` arrive
 * computed in the business timezone (§13), and each preset's membership is
 * the id list the API put in that bucket — so the picker, the dashboard's
 * ageing cards and the PLAN §1 discount window can never disagree about
 * whether a sale is "10 days old".
 */

export const PICKER_PRESETS = [
  'all',
  'older_than_5',
  'older_than_10',
  'overdue',
] as const;

export type PickerPreset = (typeof PICKER_PRESETS)[number];

const PRESET_LABEL_KEYS: Record<PickerPreset, string> = {
  all: 'settlement.presetAll',
  older_than_5: 'settlement.presetOlderThan5',
  older_than_10: 'settlement.presetOlderThan10',
  overdue: 'settlement.presetOverdue',
};

const ROWS_PER_PAGE = 25;

export function TransactionPicker({
  rows,
  buckets,
  selectedIds,
  discountMaxAgeDays,
  onSelect,
  onSelectEverything,
}: {
  rows: SettlementPickerRow[];
  buckets: SettlementPickerBuckets;
  /** Every id currently in the batch — all of them when settling everything. */
  selectedIds: number[];
  /**
   * The PLAN §1 age window, so a row past it can be marked as the reason the
   * batch will not earn the discount. 0 when the incentive is switched off.
   */
  discountMaxAgeDays: number;
  onSelect: (ids: number[]) => void;
  /** The race-proof "everything outstanding" selection (settle_all). */
  onSelectEverything: () => void;
}) {
  const { t } = useTranslation();
  const [preset, setPreset] = useState<PickerPreset>('all');
  const [page, setPage] = useState(1);

  const selected = useMemo(() => new Set(selectedIds), [selectedIds]);

  // Membership is the SERVER's: the bucket's own id list, never an age rule
  // re-evaluated here.
  const visible = useMemo(() => {
    if (preset === 'all') return rows;
    const inPreset = new Set(buckets[preset].transaction_ids);
    return rows.filter((row) => inPreset.has(row.id));
  }, [rows, buckets, preset]);

  const lastPage = Math.max(1, Math.ceil(visible.length / ROWS_PER_PAGE));

  // Over the WHOLE eligible board, not the filtered page: the column must
  // not appear and disappear as the merchant flips presets or pages.
  const showGst = anyGst(rows.map((row) => row.fee_gst_laari));

  // A preset that empties out (or a settled batch shrinking the list) must
  // never strand the merchant on a page that no longer exists.
  useEffect(() => {
    setPage((current) => Math.min(current, lastPage));
  }, [lastPage]);

  const from = (page - 1) * ROWS_PER_PAGE;
  const pageRows = visible.slice(from, from + ROWS_PER_PAGE);
  const pageIds = pageRows.map((row) => row.id);
  const allOnPageSelected =
    pageIds.length > 0 && pageIds.every((id) => selected.has(id));
  // Half a page ticked is its own state — an empty box would claim none of
  // these sales is in the batch, which is not what the merchant chose.
  const pageCheckboxState: boolean | 'indeterminate' = allOnPageSelected
    ? true
    : pageIds.some((id) => selected.has(id))
      ? 'indeterminate'
      : false;

  const choosePreset = (next: PickerPreset) => {
    setPreset(next);
    setPage(1);
    if (next === 'all') {
      onSelectEverything();
      return;
    }
    onSelect(buckets[next].transaction_ids);
  };

  const toggleRow = (id: number, checked: boolean) => {
    onSelect(
      checked
        ? [...selectedIds, id]
        : selectedIds.filter((value) => value !== id),
    );
  };

  const togglePage = (checked: boolean) => {
    onSelect(
      checked
        ? Array.from(new Set([...selectedIds, ...pageIds]))
        : selectedIds.filter((id) => !pageIds.includes(id)),
    );
  };

  return (
    <>
      <div
        role="group"
        aria-label={t('settlement.presetsLabel')}
        className="flex flex-wrap gap-2 px-5 pb-5"
      >
        {PICKER_PRESETS.map((key) => {
          const bucket = buckets[key];
          const active = preset === key;
          return (
            <button
              key={key}
              type="button"
              aria-pressed={active}
              disabled={bucket.count === 0}
              onClick={() => choosePreset(key)}
              className={cn(
                'flex min-w-32 cursor-pointer flex-col items-start gap-0.5 rounded-lg border px-3 py-2 text-start transition-colors',
                active
                  ? 'border-primary bg-primary/5'
                  : 'border-input hover:bg-accent',
                bucket.count === 0 && 'cursor-not-allowed opacity-50',
              )}
            >
              <span className="text-xs font-medium">
                {t(PRESET_LABEL_KEYS[key])}
              </span>
              <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span className="tabular-nums">{bucket.count}</span>
                <span aria-hidden>·</span>
                <MoneyText laari={bucket.due_laari} />
              </span>
            </button>
          );
        })}
      </div>

      <CardTable>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-10">
                  <Checkbox
                    checked={pageCheckboxState}
                    disabled={pageIds.length === 0}
                    onCheckedChange={(checked) => togglePage(checked === true)}
                    aria-label={t('settlement.selectAllOnPage')}
                  />
                </TableHead>
                <TableHead>{t('settlement.colInvoice')}</TableHead>
                <TableHead>{t('settlement.colDate')}</TableHead>
                <TableHead>{t('settlement.colAge')}</TableHead>
                <TableHead className="text-end">
                  {t('settlement.colCashback')}
                </TableHead>
                <TableHead className="text-end">
                  {t('settlement.colFee')}
                </TableHead>
                {showGst && (
                  <TableHead className="text-end">
                    {t('settlement.colGst')}
                  </TableHead>
                )}
                <TableHead className="text-end">
                  {t('settlement.colDue')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {pageRows.map((row) => {
                const pastWindow =
                  discountMaxAgeDays > 0 && row.age_days >= discountMaxAgeDays;
                return (
                  <TableRow key={row.id}>
                    <TableCell>
                      <Checkbox
                        checked={selected.has(row.id)}
                        onCheckedChange={(checked) =>
                          toggleRow(row.id, checked === true)
                        }
                        aria-label={t('settlement.selectRow', {
                          invoice: row.invoice_no ?? row.id,
                        })}
                      />
                    </TableCell>
                    <TableCell className="font-medium text-mono">
                      {row.invoice_no ?? `#${row.id}`}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-secondary-foreground">
                      {row.occurred_at === null
                        ? '—'
                        : format(new Date(row.occurred_at), 'dd MMM yyyy')}
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <span className="flex items-center gap-2">
                        <span
                          className={cn(
                            'tabular-nums',
                            pastWindow
                              ? 'text-secondary-foreground'
                              : 'text-muted-foreground',
                          )}
                        >
                          {t('settlement.ageDays', { count: row.age_days })}
                        </span>
                        {row.overdue && (
                          <Badge
                            size="sm"
                            appearance="light"
                            variant="destructive"
                          >
                            {t('settlement.presetOverdue')}
                          </Badge>
                        )}
                        {!row.overdue && pastWindow && (
                          <Badge size="sm" appearance="light" variant="warning">
                            {t('settlement.pastDiscountWindow')}
                          </Badge>
                        )}
                      </span>
                    </TableCell>
                    <TableCell className="text-end">
                      <MoneyText laari={row.cashback_laari} />
                    </TableCell>
                    {/* Manfaa's charge and the tax on it, side by side —
                        each the row's own stored integer, never a fee
                        recomputed and never the two added together. */}
                    <TableCell className="text-end">
                      <MoneyText laari={row.fee_laari} />
                    </TableCell>
                    {showGst && (
                      <TableCell className="text-end">
                        <MoneyText laari={row.fee_gst_laari} />
                      </TableCell>
                    )}
                    <TableCell className="text-end font-medium">
                      <MoneyText laari={row.due_laari} />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
      </CardTable>

      {visible.length > ROWS_PER_PAGE && (
        <div className="flex items-center justify-between gap-3 px-5 pt-5">
          <span className="text-sm text-muted-foreground">
            {t('settlement.pickerShowing', {
              from: from + 1,
              to: from + pageRows.length,
              total: visible.length,
            })}
          </span>
          <div className="flex items-center gap-1.5">
            <Button
              variant="outline"
              size="sm"
              mode="icon"
              aria-label={t('settlement.previousPage')}
              disabled={page <= 1}
              onClick={() => setPage((current) => current - 1)}
            >
              <ChevronLeft className="rtl:rotate-180" />
            </Button>
            <span className="text-sm tabular-nums text-secondary-foreground">
              {page} / {lastPage}
            </span>
            <Button
              variant="outline"
              size="sm"
              mode="icon"
              aria-label={t('settlement.nextPage')}
              disabled={page >= lastPage}
              onClick={() => setPage((current) => current + 1)}
            >
              <ChevronRight className="rtl:rotate-180" />
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
