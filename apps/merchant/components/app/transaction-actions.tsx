'use client';

import { useEffect, useState } from 'react';
import { parseMvrToLaari, type Transaction } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { LoaderCircle, MoreHorizontal, Pencil, Undo2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import type { CancelReason } from '@/lib/api';
import {
  apiErrorMessage,
  useAmendTransaction,
  useCancelTransaction,
} from '@/lib/queries';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

/**
 * Fixing or cancelling a sale from the transactions table.
 *
 * Both actions exist only while the sale is `awaiting_validation` and the
 * credit was not backdated — the same window the API enforces. Outside it
 * the menu is absent rather than disabled-with-a-tooltip: there is nothing
 * the cashier can do about a sale the platform now owns, and a greyed
 * control invites them to keep trying.
 *
 * A sale with a category SPLIT is corrected by editing its lines: the
 * eligible amount is their sum, exactly as it is on the credit screen, so
 * there is no separate total to type and nothing that can disagree. A
 * single-rate sale is one field.
 */

const CANCEL_REASONS: CancelReason[] = ['refund', 'void', 'duplicate', 'error'];

export function TransactionActions({
  transaction,
}: {
  transaction: Transaction;
}) {
  const { t } = useTranslation();
  const [amendOpen, setAmendOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);

  const correctable =
    transaction.state === 'awaiting_validation' && !transaction.backdated;

  if (!correctable) {
    return null;
  }

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            mode="icon"
            size="sm"
            aria-label={t('transactions.actionsLabel')}
          >
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => setAmendOpen(true)}>
            <Pencil />
            {t('transactions.amendAction')}
          </DropdownMenuItem>
          <DropdownMenuItem
            variant="destructive"
            onSelect={() => setCancelOpen(true)}
          >
            <Undo2 />
            {t('transactions.cancelAction')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <AmendDialog
        transaction={transaction}
        open={amendOpen}
        onOpenChange={setAmendOpen}
      />
      <CancelDialog
        transaction={transaction}
        open={cancelOpen}
        onOpenChange={setCancelOpen}
      />
    </>
  );
}

function AmendDialog({
  transaction,
  open,
  onOpenChange,
}: {
  transaction: Transaction;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const amend = useAmendTransaction();
  const lines = transaction.lines ?? [];
  const lined = lines.length > 0;

  const [value, setValue] = useState('');
  const [lineValues, setLineValues] = useState<string[]>([]);

  // Seeded from the current figures each time it opens, so the dialog always
  // shows what the sale says now rather than a stale draft. An effect, not
  // the onOpenChange handler: the parent menu opens this dialog by setting
  // state directly, so that handler only ever fires on CLOSE.
  useEffect(() => {
    if (!open) return;
    setValue((transaction.eligible_laari / 100).toFixed(2));
    setLineValues(lines.map((line) => (line.amount_laari / 100).toFixed(2)));
    // `lines` is derived from the transaction; depending on the row itself
    // keeps this to one re-seed per open.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, transaction]);

  const parse = (input: string): number | null => {
    try {
      return input.trim() === '' ? null : parseMvrToLaari(input);
    } catch {
      return null;
    }
  };

  const parsedLines = lined ? lineValues.map(parse) : [];
  const linesValid =
    lined && parsedLines.length === lines.length &&
    parsedLines.every((amount) => amount !== null && amount >= 1);

  // Under a split the eligible amount IS the sum of the lines — the same
  // rule the credit screen follows, so the two cannot describe the sale
  // differently.
  const parsed = lined
    ? linesValid
      ? parsedLines.reduce<number>((sum, amount) => sum + (amount as number), 0)
      : null
    : parse(value);

  const unchanged = parsed === transaction.eligible_laari;
  const valid = parsed !== null && parsed >= 1 && !unchanged;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t('transactions.amendTitle')}</DialogTitle>
          <DialogDescription>
            {t('transactions.amendBody', { invoiceNo: transaction.invoice_no })}
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          {lined ? (
            <div className="flex flex-col gap-2.5">
              <Label>{t('transactions.amendLinesLabel')}</Label>
              {lines.map((line, index) => (
                <div key={line.sort} className="flex items-center gap-3">
                  <span className="min-w-0 flex-1 truncate text-sm text-mono">
                    {line.category_name_en ??
                      t('creditSplit.everythingElse')}
                  </span>
                  <InputGroup className="w-40">
                    <InputAddon>MVR</InputAddon>
                    <Input
                      inputMode="decimal"
                      value={lineValues[index] ?? ''}
                      onChange={(event) =>
                        setLineValues((current) =>
                          current.map((value, position) =>
                            position === index ? event.target.value : value,
                          ),
                        )
                      }
                      aria-invalid={parsedLines[index] === null}
                    />
                  </InputGroup>
                </div>
              ))}
              <p className="text-xs text-muted-foreground">
                {t('transactions.amendLinesHint')}{' '}
                {parsed !== null && (
                  <MoneyText laari={parsed} className="font-medium text-mono" />
                )}
              </p>
            </div>
          ) : (
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="amend-eligible">
                {t('transactions.amendEligibleLabel')}
              </Label>
              <InputGroup>
                <InputAddon>MVR</InputAddon>
                <Input
                  id="amend-eligible"
                  inputMode="decimal"
                  value={value}
                  onChange={(event) => setValue(event.target.value)}
                  aria-invalid={value.trim() !== '' && parsed === null}
                />
              </InputGroup>
              <p className="text-xs text-muted-foreground">
                {t('transactions.amendHint')}
              </p>
            </div>
          )}

          {/* Recomputing the cashback here would be a second opinion the
              server has to agree with; it prices authoritatively, and the
              table shows the answer a moment later. What IS worth saying is
              what the sale currently stands at. */}
          <p className="text-xs text-muted-foreground">
            {t('transactions.amendCurrent')}{' '}
            <MoneyText
              laari={transaction.eligible_laari}
              className="font-medium text-mono"
            />
            {' · '}
            {t('transactions.amendCurrentCashback')}{' '}
            <MoneyText
              laari={transaction.cashback_laari}
              className="font-medium text-mono"
            />
          </p>


        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={!valid || amend.isPending}
            onClick={() =>
              amend.mutate(
                {
                  id: transaction.id,
                  eligible_amount: parsed as number,
                  ...(lined
                    ? {
                        lines: lines.map((line, index) => ({
                          category: line.category,
                          amount_laari: parsedLines[index] as number,
                        })),
                      }
                    : {}),
                },
                {
                  onSuccess: () => {
                    toast.success(t('transactions.amendDone'));
                    onOpenChange(false);
                  },
                  onError: (error) =>
                    toast.error(
                      apiErrorMessage(error, t('transactions.amendFailed')),
                    ),
                },
              )
            }
          >
            {amend.isPending && <LoaderCircle className="animate-spin" />}
            {t('transactions.amendSubmit')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function CancelDialog({
  transaction,
  open,
  onOpenChange,
}: {
  transaction: Transaction;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const cancel = useCancelTransaction();
  const [reason, setReason] = useState<CancelReason>('refund');
  const [note, setNote] = useState('');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t('transactions.cancelTitle')}</DialogTitle>
          <DialogDescription>
            {t('transactions.cancelBody', { invoiceNo: transaction.invoice_no })}
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="cancel-reason">
              {t('transactions.cancelReasonLabel')}
            </Label>
            <Select
              value={reason}
              onValueChange={(next) => setReason(next as CancelReason)}
            >
              <SelectTrigger id="cancel-reason">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {CANCEL_REASONS.map((value) => (
                  <SelectItem key={value} value={value}>
                    {t(`transactions.cancelReason.${value}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="cancel-note">
              {t('transactions.cancelNoteLabel')}
            </Label>
            <Textarea
              id="cancel-note"
              value={note}
              maxLength={500}
              rows={2}
              onChange={(event) => setNote(event.target.value)}
            />
          </div>

          {/* The customer has already been told they earned this. */}
          <p className="text-xs text-destructive">
            {t('transactions.cancelWarning')}
          </p>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="destructive"
            disabled={cancel.isPending}
            onClick={() =>
              cancel.mutate(
                {
                  id: transaction.id,
                  reason,
                  note: note.trim() === '' ? null : note.trim(),
                },
                {
                  onSuccess: () => {
                    toast.success(t('transactions.cancelDone'));
                    onOpenChange(false);
                  },
                  onError: (error) =>
                    toast.error(
                      apiErrorMessage(error, t('transactions.cancelFailed')),
                    ),
                },
              )
            }
          >
            {cancel.isPending && <LoaderCircle className="animate-spin" />}
            {t('transactions.cancelSubmit')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
