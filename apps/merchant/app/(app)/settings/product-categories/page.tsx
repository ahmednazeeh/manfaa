'use client';

import { useState } from 'react';
import { redirect } from 'next/navigation';
import {
  bpToPercentString,
  parsePercentToBp,
  type ProductCategory,
} from '@manfaa/api-client';
import { Ban, LoaderCircle, Pencil, Plus, RotateCcw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { formatBp, formatRateOrDash, trimRate } from '@/lib/estimate';
import {
  apiErrorMessage,
  isRateNotPriced,
  rateNotPricedMessage,
  useCreateProductCategory,
  useProductCategories,
  useUpdateProductCategory,
} from '@/lib/queries';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTable, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';

/**
 * Settings > Product categories (Task #25). Per-store categories that
 * override the standing rate per sale LINE: `excluded` earns nothing (even
 * during promotions), `rate` carries its own percent. Anything not listed —
 * the credit form's "Everything else" bucket — earns the standing rate.
 *
 * The slug is generated once from the English name and never changes (it is
 * the vendors' lines[].category key and historical lines snapshot it), so
 * renames are safe. Mode/rate changes reprice FUTURE sales only, and
 * deactivation is the only removal — history must keep resolving.
 */

/** §4 structural bounds for a category rate override, integer bp. */
const MIN_RATE_BP = 50;
const MAX_RATE_BP = 2000;

interface CategoryFormState {
  nameEn: string;
  nameDv: string;
  mode: 'excluded' | 'rate';
  ratePercent: string;
  sort: string;
}

function emptyForm(): CategoryFormState {
  return { nameEn: '', nameDv: '', mode: 'rate', ratePercent: '', sort: '0' };
}

function formFromCategory(category: ProductCategory): CategoryFormState {
  return {
    nameEn: category.name_en,
    nameDv: category.name_dv ?? '',
    mode: category.mode,
    ratePercent:
      category.cashback_rate_percent === null
        ? ''
        : trimRate(category.cashback_rate_percent),
    sort: String(category.sort),
  };
}

/** The category's display name in the current language, en fallback. */
function categoryLabel(category: ProductCategory, language: string): string {
  return language === 'dv' && category.name_dv !== null
    ? category.name_dv
    : category.name_en;
}

function CategoryDialog({
  title,
  submitLabel,
  initial,
  open,
  busy,
  serverError,
  onOpenChange,
  onSubmit,
}: {
  title: string;
  submitLabel: string;
  initial: CategoryFormState;
  open: boolean;
  busy: boolean;
  /** A rate_not_priced (or similar) message rendered inside the dialog. */
  serverError: string | null;
  onOpenChange: (open: boolean) => void;
  onSubmit: (values: {
    nameEn: string;
    nameDv: string;
    mode: 'excluded' | 'rate';
    rateBp: number | null;
    sort: number;
  }) => void;
}) {
  const { t } = useTranslation();
  // The parent remounts this dialog (via `key`) whenever it opens, so the
  // initial values are fresh per open.
  const [form, setForm] = useState(initial);

  const trimmedRate = form.ratePercent.trim();
  const rateBp = trimmedRate === '' ? null : parsePercentToBp(trimmedRate);
  const rateError =
    form.mode !== 'rate'
      ? null
      : trimmedRate === ''
        ? null // required, but not shouted at until typed — submit stays off
        : rateBp === null
          ? t('productCategories.ratePercentInvalid')
          : rateBp < MIN_RATE_BP
            ? t('productCategories.rateTooLow', { min: formatBp(MIN_RATE_BP) })
            : rateBp > MAX_RATE_BP
              ? t('productCategories.rateTooHigh', {
                  max: formatBp(MAX_RATE_BP),
                })
              : null;

  const sortValue = /^\d{1,6}$/.test(form.sort.trim())
    ? Number(form.sort.trim())
    : null;

  const canSubmit =
    form.nameEn.trim() !== '' &&
    // The Dhivehi name is REQUIRED: these names print on the customer's own
    // receipt lines, and a Latin category name on an otherwise Dhivehi
    // receipt is the readability problem the rule exists to prevent.
    form.nameDv.trim() !== '' &&
    sortValue !== null &&
    sortValue <= 100000 &&
    (form.mode === 'excluded' || (rateBp !== null && rateError === null)) &&
    !busy;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="category-name-en">
              {t('productCategories.nameEnLabel')}
            </Label>
            <Input
              id="category-name-en"
              value={form.nameEn}
              maxLength={120}
              placeholder={t('productCategories.nameEnPlaceholder')}
              onChange={(event) =>
                setForm({ ...form, nameEn: event.target.value })
              }
            />
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="category-name-dv">
              {t('productCategories.nameDvLabel')}
            </Label>
            <Input
              id="category-name-dv"
              dir="rtl"
              lang="dv"
              value={form.nameDv}
              maxLength={120}
              onChange={(event) =>
                setForm({ ...form, nameDv: event.target.value })
              }
            />
            <p className="text-xs text-muted-foreground">
              {t('productCategories.nameDvHint')}
            </p>
          </div>

          <div className="flex flex-col gap-2.5">
            <Label>{t('productCategories.modeLabel')}</Label>
            <RadioGroup
              value={form.mode}
              onValueChange={(value) =>
                setForm({ ...form, mode: value as 'excluded' | 'rate' })
              }
              className="flex flex-col gap-2.5"
            >
              <label className="flex items-start gap-2.5 cursor-pointer">
                <RadioGroupItem value="rate" className="mt-0.5" />
                <span className="flex flex-col">
                  <span className="text-sm font-medium">
                    {t('productCategories.modeRate')}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {t('productCategories.modeRateHint')}
                  </span>
                </span>
              </label>
              <label className="flex items-start gap-2.5 cursor-pointer">
                <RadioGroupItem value="excluded" className="mt-0.5" />
                <span className="flex flex-col">
                  <span className="text-sm font-medium">
                    {t('productCategories.modeExcluded')}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {t('productCategories.modeExcludedHint')}
                  </span>
                </span>
              </label>
            </RadioGroup>
          </div>

          {form.mode === 'rate' && (
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="category-rate">
                {t('productCategories.rateLabel')}
              </Label>
              <InputGroup className="w-44">
                <Input
                  id="category-rate"
                  inputMode="decimal"
                  value={form.ratePercent}
                  placeholder="2.50"
                  aria-invalid={rateError !== null}
                  onChange={(event) =>
                    setForm({ ...form, ratePercent: event.target.value })
                  }
                />
                <InputAddon>%</InputAddon>
              </InputGroup>
              {rateError !== null ? (
                <p className="text-xs text-destructive">{rateError}</p>
              ) : (
                <p className="text-xs text-muted-foreground">
                  {t('productCategories.rateHint', {
                    min: formatBp(MIN_RATE_BP),
                    max: formatBp(MAX_RATE_BP),
                  })}
                </p>
              )}
            </div>
          )}

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="category-sort">
              {t('productCategories.sortLabel')}
            </Label>
            <Input
              id="category-sort"
              className="w-28"
              inputMode="numeric"
              value={form.sort}
              aria-invalid={sortValue === null || sortValue > 100000}
              onChange={(event) =>
                setForm({ ...form, sort: event.target.value })
              }
            />
            <p className="text-xs text-muted-foreground">
              {t('productCategories.sortHint')}
            </p>
          </div>

          {serverError !== null && (
            <p className="text-sm text-destructive">{serverError}</p>
          )}
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={!canSubmit}
            onClick={() =>
              onSubmit({
                nameEn: form.nameEn.trim(),
                nameDv: form.nameDv.trim(),
                mode: form.mode,
                rateBp: form.mode === 'rate' ? rateBp : null,
                sort: sortValue as number,
              })
            }
          >
            {busy && <LoaderCircle className="animate-spin" />}
            {submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** The per-category half of the merged Cashback settings screen. */
export function ProductCategoriesSection() {
  const { t, i18n } = useTranslation();
  const categories = useProductCategories();
  const createCategory = useCreateProductCategory();
  const updateCategory = useUpdateProductCategory();

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<ProductCategory | null>(null);
  const [deactivating, setDeactivating] = useState<ProductCategory | null>(
    null,
  );
  const [dialogError, setDialogError] = useState<string | null>(null);

  const failureMessage = (error: unknown, fallback: string): string =>
    isRateNotPriced(error)
      ? rateNotPricedMessage(error)
      : apiErrorMessage(error, fallback);

  const handleCreate = (values: {
    nameEn: string;
    nameDv: string;
    mode: 'excluded' | 'rate';
    rateBp: number | null;
    sort: number;
  }) => {
    setDialogError(null);
    const base = {
      name_en: values.nameEn,
      name_dv: values.nameDv,
      sort: values.sort,
    };
    createCategory.mutate(
      values.mode === 'rate'
        ? {
            ...base,
            mode: 'rate',
            // PLAN §1 wire format: a 2-decimal percent string out; the bp
            // above is what the form's range check compared.
            cashback_rate_percent: bpToPercentString(values.rateBp as number),
          }
        : { ...base, mode: 'excluded' },
      {
        onSuccess: (response) => {
          toast.success(
            t('productCategories.created', { name: response.data.name_en }),
          );
          setCreating(false);
        },
        onError: (error) =>
          setDialogError(
            failureMessage(error, t('productCategories.createFailed')),
          ),
      },
    );
  };

  const handleEdit = (values: {
    nameEn: string;
    nameDv: string;
    mode: 'excluded' | 'rate';
    rateBp: number | null;
    sort: number;
  }) => {
    if (!editing) return;
    setDialogError(null);
    updateCategory.mutate(
      {
        id: editing.id,
        body: {
          name_en: values.nameEn,
          name_dv: values.nameDv,
          mode: values.mode,
          // Switching to excluded must clear the rate explicitly — the
          // server validates the FINAL (post-merge) mode/rate pair.
          cashback_rate_percent:
            values.mode === 'rate'
              ? bpToPercentString(values.rateBp as number)
              : null,
          sort: values.sort,
        },
      },
      {
        onSuccess: () => {
          toast.success(t('productCategories.saved'));
          setEditing(null);
        },
        onError: (error) =>
          setDialogError(
            failureMessage(error, t('productCategories.saveFailed')),
          ),
      },
    );
  };

  const setActive = (category: ProductCategory, active: boolean) => {
    updateCategory.mutate(
      { id: category.id, body: { active } },
      {
        onSuccess: () => {
          toast.success(
            t(
              active
                ? 'productCategories.reactivated'
                : 'productCategories.deactivated',
              { name: category.name_en },
            ),
          );
          setDeactivating(null);
        },
        onError: (error) => {
          toast.error(
            apiErrorMessage(error, t('productCategories.saveFailed')),
          );
          setDeactivating(null);
        },
      },
    );
  };

  return (
    <div>
      <div className="flex justify-end pb-4">
        <Button onClick={() => setCreating(true)}>
          <Plus />
          {t('productCategories.add')}
        </Button>
      </div>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>{t('productCategories.listTitle')}</CardTitle>
        </CardHeader>

        {categories.error ? (
          <ErrorBlock error={categories.error} />
        ) : !categories.data ? (
          <LoadingBlock lines={4} />
        ) : categories.data.length === 0 ? (
          <EmptyBlock>{t('productCategories.empty')}</EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('productCategories.colName')}</TableHead>
                    <TableHead>{t('productCategories.colCashback')}</TableHead>
                    <TableHead className="w-16">
                      {t('productCategories.colSort')}
                    </TableHead>
                    <TableHead>{t('productCategories.colStatus')}</TableHead>
                    <TableHead className="w-24 text-end">
                      {t('productCategories.colActions')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {categories.data.map((category) => (
                    <TableRow
                      key={category.id}
                      className={category.active ? '' : 'opacity-60'}
                    >
                      <TableCell>
                        <div className="flex flex-col">
                          <span className="font-medium">
                            {categoryLabel(category, i18n.language)}
                          </span>
                          <span
                            className="text-xs text-muted-foreground text-mono"
                            dir="ltr"
                          >
                            {category.slug}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell>
                        {category.mode === 'excluded' ? (
                          <Badge variant="warning" appearance="light">
                            {t('productCategories.excludedBadge')}
                          </Badge>
                        ) : (
                          <span className="font-medium tabular-nums">
                            {formatRateOrDash(category.cashback_rate_percent)}
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="text-secondary-foreground tabular-nums">
                        {category.sort}
                      </TableCell>
                      <TableCell>
                        {category.active ? (
                          <Badge variant="success" appearance="light">
                            {t('productCategories.activeBadge')}
                          </Badge>
                        ) : (
                          <Badge variant="secondary" appearance="light">
                            {t('productCategories.inactiveBadge')}
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-end">
                        <div className="inline-flex gap-1">
                          <Button
                            variant="ghost"
                            mode="icon"
                            size="sm"
                            aria-label={t('productCategories.editAria', {
                              name: category.name_en,
                            })}
                            onClick={() => {
                              setDialogError(null);
                              setEditing(category);
                            }}
                          >
                            <Pencil />
                          </Button>
                          {category.active ? (
                            <Button
                              variant="ghost"
                              mode="icon"
                              size="sm"
                              aria-label={t(
                                'productCategories.deactivateAria',
                                { name: category.name_en },
                              )}
                              onClick={() => setDeactivating(category)}
                            >
                              <Ban />
                            </Button>
                          ) : (
                            <Button
                              variant="ghost"
                              mode="icon"
                              size="sm"
                              disabled={updateCategory.isPending}
                              aria-label={t(
                                'productCategories.reactivateAria',
                                { name: category.name_en },
                              )}
                              onClick={() => setActive(category, true)}
                            >
                              <RotateCcw />
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        )}
      </Card>

      <CategoryDialog
        key={creating ? 'create-open' : 'create-closed'}
        title={t('productCategories.add')}
        submitLabel={t('productCategories.add')}
        initial={emptyForm()}
        open={creating}
        busy={createCategory.isPending}
        serverError={creating ? dialogError : null}
        onOpenChange={(open) => {
          setCreating(open);
          if (!open) setDialogError(null);
        }}
        onSubmit={handleCreate}
      />

      <CategoryDialog
        key={editing ? `edit-${editing.id}` : 'edit-closed'}
        title={t('productCategories.editTitle', {
          name: editing?.name_en ?? '',
        })}
        submitLabel={t('common.save')}
        initial={editing ? formFromCategory(editing) : emptyForm()}
        open={editing !== null}
        busy={updateCategory.isPending}
        serverError={editing !== null ? dialogError : null}
        onOpenChange={(open) => {
          if (!open) {
            setEditing(null);
            setDialogError(null);
          }
        }}
        onSubmit={handleEdit}
      />

      <AlertDialog
        open={deactivating !== null}
        onOpenChange={(open) => {
          if (!open) setDeactivating(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('productCategories.deactivateTitle', {
                name: deactivating?.name_en ?? '',
              })}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('productCategories.deactivateBody')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              disabled={updateCategory.isPending}
              onClick={() => deactivating && setActive(deactivating, false)}
            >
              {t('productCategories.deactivateConfirm')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

/** The old standalone screen forwards to the merged one (owner, 2026-08-17). */
export default function ProductCategoriesSettingsPage() {
  redirect('/settings/cashback');
}
