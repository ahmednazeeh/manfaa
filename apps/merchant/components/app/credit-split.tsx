'use client';

import { useMemo } from 'react';
import {
  parseMvrToLaari,
  percentToBp,
  type ProductCategory,
  type TransactionLine,
} from '@manfaa/api-client';
import { MoneyText, useFormatMoney } from '@manfaa/ui';
import { Plus, Trash2, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
  estimateFeeBpFor,
  estimateLaariAtBp,
  formatBp,
  formatRate,
} from '@/lib/estimate';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

/**
 * The credit screen's optional split-by-category editor (Task #25). Each
 * line prices at its own effective rate — excluded → nothing, category
 * override → its rate, the "Everything else" bucket → the sale's BASE rate
 * — and during a live promotion every NON-excluded line earns
 * max(promotion rate, its own rate); exclusions always hold. Everything
 * here is a client-side ESTIMATE mirroring the §4 per-line ceiling math;
 * the server prices authoritatively at the sale time.
 *
 * The BASE rate is the store's standing rate, or the per-sale
 * `cashback_rate_percent` override when the cashier set one (PLAN §1): the
 * override simply replaces the standing rate one rung higher, so lines that
 * would price at standing price at the override instead, category rates and
 * exclusions are untouched, and the promotion floor still lifts any line a
 * live promo would pay more on. Since the server refuses an override below
 * the advertised rate, no line can ever earn less than it would without one.
 */

/** Sentinel for the default "Everything else" bucket (category: null). */
export const DEFAULT_BUCKET = '__everything_else__';

export interface SplitRow {
  /** Stable client-side key. */
  key: number;
  /** DEFAULT_BUCKET or a product-category slug. */
  category: string;
  /** Raw MVR amount input. */
  amount: string;
}

export type LinePricing =
  | { kind: 'excluded' }
  | { kind: 'category'; rateBp: number }
  | { kind: 'standing'; rateBp: number }
  /** The default bucket under a per-sale rate override (PLAN §1). */
  | { kind: 'override'; rateBp: number }
  | {
      kind: 'promotion';
      rateBp: number;
      /** The rate the line would earn without the promotion. */
      ownRateBp: number;
    };

/**
 * The sale's BASE terms: the standing rate, or the per-sale override when
 * the cashier set one. `fromOverride` only changes the wording of the
 * per-line hint — the pricing rule is identical either way.
 */
export interface SplitBaseTerms {
  rateBp: number | null;
  /** Null when the fee is unknown (stranded rate, or an override the panel prices statically). */
  feeBp: number | null;
  fromOverride: boolean;
}

/** The live promotion applying to this SALE, already resolved by the caller. */
export interface SplitPromoTerms {
  rateBp: number | null;
  feeBp: number | null;
}

export interface AnalyzedLine {
  row: SplitRow;
  /** Parsed integer laari; null when empty or not a valid amount >= 0.01. */
  amountLaari: number | null;
  /** Same bucket/category picked on an earlier row. */
  duplicate: boolean;
  /** The slug no longer resolves to an active category. */
  unknownCategory: boolean;
  /** Null when the standing rate is needed but unknown. */
  pricing: LinePricing | null;
  cashbackEstimate: number | null;
  feeEstimate: number | null;
}

export interface SplitAnalysis {
  lines: AnalyzedLine[];
  /** Sum of parsed line amounts; null while any line amount is invalid. */
  sumLaari: number | null;
  /** Sum matches the eligible amount (both known). */
  matches: boolean;
  /** Both known and different — the server would 422 lines_sum_mismatch. */
  mismatch: boolean;
  /** Every row parses, no duplicates, no unknown categories. */
  rowsValid: boolean;
  /** rowsValid AND the sum equals the eligible amount. */
  submittable: boolean;
  cashbackTotal: number | null;
  feeTotal: number | null;
}

/** parseMvrToLaari without the throw: null when not a valid MVR amount. */
function safeParseMvr(input: string): number | null {
  try {
    return parseMvrToLaari(input);
  } catch {
    return null;
  }
}

/**
 * Pure analysis of the editor rows against the merchant's terms. Both sides
 * are already resolved by the caller: `base` is the standing rate or the
 * per-sale override, and `promo.rateBp` is non-null only when a live
 * merchant-wide promotion applies to this SALE (its minimum purchase is met
 * by the WHOLE eligible amount).
 */
export function analyzeSplit(
  rows: SplitRow[],
  categories: ProductCategory[],
  base: SplitBaseTerms,
  promo: SplitPromoTerms,
  eligibleLaari: number | null,
): SplitAnalysis {
  const { rateBp: baseRateBp, feeBp: baseFeeBp, fromOverride } = base;
  const { rateBp: promoRateBp, feeBp: promoFeeBp } = promo;
  const seen = new Set<string>();
  const lines: AnalyzedLine[] = rows.map((row) => {
    const duplicate = seen.has(row.category);
    seen.add(row.category);

    const raw = row.amount.trim();
    const parsed = raw === '' ? null : safeParseMvr(raw);
    const amountLaari = parsed !== null && parsed >= 1 ? parsed : null;

    const category =
      row.category === DEFAULT_BUCKET
        ? null
        : (categories.find((entry) => entry.slug === row.category) ?? null);
    const unknownCategory =
      row.category !== DEFAULT_BUCKET && category === null;

    let pricing: LinePricing | null = null;
    if (!unknownCategory) {
      if (category !== null && category.mode === 'excluded') {
        pricing = { kind: 'excluded' };
      } else {
        const categoryRateBp =
          category !== null && category.cashback_rate_percent !== null
            ? percentToBp(category.cashback_rate_percent)
            : null;
        const own =
          categoryRateBp !== null
            ? { kind: 'category' as const, rateBp: categoryRateBp }
            : baseRateBp !== null
              ? {
                  kind: fromOverride
                    ? ('override' as const)
                    : ('standing' as const),
                  rateBp: baseRateBp,
                }
              : null;
        if (own !== null) {
          pricing =
            promoRateBp !== null && promoRateBp > own.rateBp
              ? {
                  kind: 'promotion',
                  rateBp: promoRateBp,
                  ownRateBp: own.rateBp,
                }
              : own;
        }
      }
    }

    let cashbackEstimate: number | null = null;
    let feeEstimate: number | null = null;
    if (pricing !== null && amountLaari !== null) {
      if (pricing.kind === 'excluded') {
        cashbackEstimate = 0;
        feeEstimate = 0;
      } else {
        cashbackEstimate = estimateLaariAtBp(amountLaari, pricing.rateBp);
        const feeBp =
          pricing.kind === 'standing' || pricing.kind === 'override'
            ? (baseFeeBp ?? estimateFeeBpFor(pricing.rateBp))
            : pricing.kind === 'promotion'
              ? (promoFeeBp ?? estimateFeeBpFor(pricing.rateBp))
              : estimateFeeBpFor(pricing.rateBp);
        feeEstimate = estimateLaariAtBp(amountLaari, feeBp);
      }
    }

    return {
      row,
      amountLaari,
      duplicate,
      unknownCategory,
      pricing,
      cashbackEstimate,
      feeEstimate,
    };
  });

  const allAmountsValid = lines.every((line) => line.amountLaari !== null);
  const sumLaari = allAmountsValid
    ? lines.reduce((sum, line) => sum + (line.amountLaari as number), 0)
    : null;
  const matches =
    sumLaari !== null && eligibleLaari !== null && sumLaari === eligibleLaari;
  const mismatch =
    sumLaari !== null && eligibleLaari !== null && sumLaari !== eligibleLaari;
  const rowsValid =
    lines.length > 0 &&
    allAmountsValid &&
    lines.every((line) => !line.duplicate && !line.unknownCategory);

  const allPriced = lines.every(
    (line) => line.cashbackEstimate !== null && line.feeEstimate !== null,
  );
  const cashbackTotal =
    allPriced && lines.length > 0
      ? lines.reduce((sum, line) => sum + (line.cashbackEstimate as number), 0)
      : null;
  const feeTotal =
    allPriced && lines.length > 0
      ? lines.reduce((sum, line) => sum + (line.feeEstimate as number), 0)
      : null;

  return {
    lines,
    sumLaari,
    matches,
    mismatch,
    rowsValid,
    submittable: rowsValid && matches,
    cashbackTotal,
    feeTotal,
  };
}

/** The category's display name in the current language, en fallback. */
function categoryLabel(category: ProductCategory, language: string): string {
  return language === 'dv' && category.name_dv !== null
    ? category.name_dv
    : category.name_en;
}

function PricingPreview({ line }: { line: AnalyzedLine }) {
  const { t } = useTranslation();

  if (line.unknownCategory) {
    return (
      <p className="text-xs text-destructive">
        {t('creditSplit.unknownCategory')}
      </p>
    );
  }
  if (line.duplicate) {
    return (
      <p className="text-xs text-destructive">
        {t('creditSplit.duplicateCategory')}
      </p>
    );
  }
  if (line.pricing === null) {
    return (
      <p className="text-xs text-muted-foreground">
        {t('creditSplit.rateUnknown')}
      </p>
    );
  }

  switch (line.pricing.kind) {
    case 'excluded':
      return (
        <p className="text-xs text-muted-foreground">
          <Badge variant="warning" appearance="light" size="sm">
            {t('creditSplit.excludedBadge')}
          </Badge>{' '}
          {t('creditSplit.excludedNote')}
        </p>
      );
    case 'promotion':
      return (
        <p className="text-xs text-muted-foreground">
          <Badge variant="info" appearance="light" size="sm">
            {t('creditSplit.promoBadge', {
              rate: formatBp(line.pricing.rateBp),
            })}
          </Badge>{' '}
          {t('creditSplit.promoBeats', {
            own: formatBp(line.pricing.ownRateBp),
          })}
        </p>
      );
    case 'category':
      return (
        <p className="text-xs text-muted-foreground">
          {t('creditSplit.categoryRate', {
            rate: formatBp(line.pricing.rateBp),
          })}
        </p>
      );
    case 'standing':
      return (
        <p className="text-xs text-muted-foreground">
          {t('creditSplit.standingRate', {
            rate: formatBp(line.pricing.rateBp),
          })}
        </p>
      );
    case 'override':
      return (
        <p className="text-xs text-muted-foreground">
          {t('creditSplit.overrideRate', {
            rate: formatBp(line.pricing.rateBp),
          })}
        </p>
      );
  }
}

export function SplitEditor({
  rows,
  onRowsChange,
  categories,
  analysis,
  eligibleLaari,
  onUseLinesTotal,
}: {
  rows: SplitRow[];
  onRowsChange: (rows: SplitRow[]) => void;
  /** ACTIVE categories only. */
  categories: ProductCategory[];
  analysis: SplitAnalysis;
  eligibleLaari: number | null;
  /** Adopts the lines' own sum as the eligible amount, clearing a mismatch. */
  onUseLinesTotal: () => void;
}) {
  const { t, i18n } = useTranslation();
  const formatMoney = useFormatMoney();

  const usedKeys = useMemo(
    () => new Set(rows.map((row) => row.category)),
    [rows],
  );
  const allOptionKeys = useMemo(
    () => [DEFAULT_BUCKET, ...categories.map((entry) => entry.slug)],
    [categories],
  );
  const firstUnused = allOptionKeys.find((key) => !usedKeys.has(key));

  const optionLabel = (key: string): string => {
    if (key === DEFAULT_BUCKET) return t('creditSplit.everythingElse');
    const category = categories.find((entry) => entry.slug === key);
    return category === undefined
      ? key
      : categoryLabel(category, i18n.language);
  };

  const addRow = () => {
    if (firstUnused === undefined) return;
    const nextKey = rows.reduce((max, row) => Math.max(max, row.key), 0) + 1;
    onRowsChange([
      ...rows,
      { key: nextKey, category: firstUnused, amount: '' },
    ]);
  };

  const updateRow = (key: number, patch: Partial<Omit<SplitRow, 'key'>>) => {
    onRowsChange(
      rows.map((row) => (row.key === key ? { ...row, ...patch } : row)),
    );
  };

  const removeRow = (key: number) => {
    if (rows.length <= 1) return;
    onRowsChange(rows.filter((row) => row.key !== key));
  };

  return (
    <div className="flex flex-col gap-4 rounded-lg border border-border p-4">
      {analysis.lines.map((line) => {
        const amountInvalid =
          line.row.amount.trim() !== '' && line.amountLaari === null;
        return (
          <div key={line.row.key} className="flex flex-col gap-1.5">
            <div className="flex items-start gap-2.5">
              <div className="flex-1 min-w-0">
                <Select
                  value={line.row.category}
                  onValueChange={(value) =>
                    updateRow(line.row.key, { category: value })
                  }
                >
                  <SelectTrigger
                    aria-label={t('creditSplit.categoryAria')}
                    aria-invalid={line.duplicate || line.unknownCategory}
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {allOptionKeys.map((key) => (
                      <SelectItem
                        key={key}
                        value={key}
                        disabled={
                          key !== line.row.category && usedKeys.has(key)
                        }
                      >
                        {optionLabel(key)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <InputGroup className="w-40 shrink-0">
                <InputAddon>MVR</InputAddon>
                <Input
                  inputMode="decimal"
                  value={line.row.amount}
                  placeholder="0.00"
                  aria-label={t('creditSplit.amountAria')}
                  aria-invalid={amountInvalid}
                  onChange={(event) =>
                    updateRow(line.row.key, { amount: event.target.value })
                  }
                />
              </InputGroup>
              <Button
                variant="ghost"
                mode="icon"
                size="sm"
                className="shrink-0 mt-1"
                aria-label={t('creditSplit.removeLine')}
                disabled={rows.length <= 1}
                onClick={() => removeRow(line.row.key)}
              >
                <Trash2 />
              </Button>
            </div>
            <div className="flex items-center justify-between gap-3">
              {amountInvalid ? (
                <p className="text-xs text-destructive">
                  {t('creditSplit.amountInvalid')}
                </p>
              ) : (
                <PricingPreview line={line} />
              )}
              {line.cashbackEstimate !== null && (
                <span className="text-xs text-muted-foreground whitespace-nowrap">
                  {t('creditSplit.lineCashback')}{' '}
                  <MoneyText laari={line.cashbackEstimate} />
                </span>
              )}
            </div>
          </div>
        );
      })}

      <div className="flex items-center justify-between gap-3">
        <Button
          variant="outline"
          size="sm"
          disabled={firstUnused === undefined}
          onClick={addRow}
        >
          <Plus />
          {t('creditSplit.addLine')}
        </Button>
        <span className="text-sm">
          <span className="text-muted-foreground">
            {t('creditSplit.linesTotal')}
          </span>{' '}
          {analysis.sumLaari !== null ? (
            <MoneyText
              laari={analysis.sumLaari}
              className={
                analysis.mismatch ? 'text-destructive font-medium' : ''
              }
            />
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
          {eligibleLaari !== null && (
            <>
              {' '}
              <span className="text-muted-foreground">
                {t('creditSplit.ofEligible')}
              </span>{' '}
              <MoneyText laari={eligibleLaari} />
            </>
          )}
        </span>
      </div>

      {analysis.mismatch &&
        analysis.sumLaari !== null &&
        eligibleLaari !== null && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>{t('creditSplit.mismatchTitle')}</AlertTitle>
              <AlertDescription>
                {t('creditSplit.mismatchBody', {
                  // Integer-only money formatting (no float division).
                  difference: `${analysis.sumLaari > eligibleLaari ? '+' : '-'}${formatMoney(
                    Math.abs(analysis.sumLaari - eligibleLaari),
                  )}`,
                })}
              </AlertDescription>
              {/* The overwhelmingly common cause is a total typed before the
                  split was itemised — usually the earning part only, with an
                  excluded category left out. One click adopts the lines. */}
              <Button
                variant="outline"
                size="sm"
                className="mt-2 self-start"
                onClick={onUseLinesTotal}
              >
                {t('creditSplit.useLinesTotal', {
                  amount: formatMoney(analysis.sumLaari),
                })}
              </Button>
            </AlertContent>
          </Alert>
        )}
    </div>
  );
}

/**
 * The priced lines of a recorded credit, straight from the server's stored
 * per-line integers (immutable snapshots — totals are their exact sum).
 */
export function ResultLines({
  lines,
  categories,
}: {
  lines: TransactionLine[];
  categories: ProductCategory[];
}) {
  const { t, i18n } = useTranslation();

  const lineName = (line: TransactionLine): string => {
    if (line.category === null) return t('creditSplit.everythingElse');
    const category = categories.find((entry) => entry.slug === line.category);
    if (category !== undefined) return categoryLabel(category, i18n.language);
    return line.category_name_en ?? line.category;
  };

  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('creditSplit.colItem')}</TableHead>
            <TableHead className="text-end">
              {t('creditSplit.colAmount')}
            </TableHead>
            <TableHead>{t('creditSplit.colRate')}</TableHead>
            <TableHead className="text-end">
              {t('creditSplit.colCashback')}
            </TableHead>
            <TableHead className="text-end">
              {t('creditSplit.colFee')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {lines.map((line) => (
            <TableRow key={`${line.sort}-${line.category ?? 'default'}`}>
              <TableCell className="font-medium">{lineName(line)}</TableCell>
              <TableCell className="text-end">
                <MoneyText laari={line.amount_laari} />
              </TableCell>
              <TableCell>
                <span className="inline-flex items-center gap-1.5">
                  <span className="tabular-nums">
                    {formatRate(line.cashback_rate_percent)}
                  </span>
                  {line.priced_by === 'promotion' && (
                    <Badge variant="info" appearance="light" size="sm">
                      {t('creditSplit.pricedByPromotion')}
                    </Badge>
                  )}
                  {line.priced_by === 'excluded' && (
                    <Badge variant="warning" appearance="light" size="sm">
                      {t('creditSplit.excludedBadge')}
                    </Badge>
                  )}
                </span>
              </TableCell>
              <TableCell className="text-end">
                <MoneyText laari={line.cashback_laari} />
              </TableCell>
              <TableCell className="text-end">
                <MoneyText laari={line.fee_laari} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
