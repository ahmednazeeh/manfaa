'use client';

import { useEffect, useState } from 'react';
import { parseMvrToLaari, type SettlementPayment } from '@manfaa/api-client';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { CircleCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  DiscrepancyBadge,
  laariToInput,
} from '@/components/transfers/claim-and-fact';
import { ClaimVerdict } from './claim-verdict';

/**
 * THE ONE PLACE A SETTLEMENT PAYMENT IS MATCHED (owner, 2026-08-25).
 *
 * What allocates a batch is what the BANK sent, not what the merchant typed.
 * Where the verifier found the transfer it has already stamped the row's
 * `received_laari` and this dialog simply shows it. Where a person is
 * reconciling off a statement there IS no bank figure on the row — so the
 * reviewer states it, and what they type is what allocates.
 *
 * THE FIELD STARTS EMPTY WHERE NO BANK FIGURE EXISTS (verifier round,
 * 2026-08-25). It used to be prefilled with the merchant's claim, which
 * meant every hand match from here stored that CLAIM in the column the
 * resources, both merchant apps and the review card all present as "what the
 * bank credited" — so an auditor could no longer tell a bank-attested figure
 * from a rubber-stamped default, and the panel's own "Not recorded" copy
 * became unreachable. Left empty the request omits the field, which is the
 * server's honest "nobody ever stated a figure" and the branch it and its
 * test already support. Where the row DOES carry a bank figure (the verifier
 * stamped it) that figure is shown, because it is a fact rather than a guess.
 *
 * The §7 verdict underneath recomputes as they type — so a short transfer is
 * seen to be short BEFORE the button is pressed, rather than discovered
 * afterwards in a partially_settled batch.
 *
 * The REFERENCE is asked for on the same statement line, and only when the
 * merchant quoted none: without it a hand-matched credit is named nowhere,
 * so BankCreditClaim reports it unspent forever and the same transfer can go
 * on to fund a wallet top-up or a customer order.
 */
export function MatchPaymentDialog({
  payment,
  outstanding,
  matching,
  onConfirm,
  size = 'md',
}: {
  payment: SettlementPayment;
  /** What the batch still owes, for the §7 verdict. */
  outstanding: number;
  matching: boolean;
  /**
   * Runs the match. Resolves once the server has answered — the dialog
   * closes then, and stays open on a rejection so the reviewer keeps their
   * figure and can read the error.
   */
  onConfirm: (
    receivedLaari: number | null,
    bankRef: string | null,
  ) => Promise<unknown>;
  size?: 'md' | 'sm';
}) {
  const [open, setOpen] = useState(false);
  const prefill =
    payment.received_laari === null ? '' : laariToInput(payment.received_laari);
  const [received, setReceived] = useState(prefill);
  const [bankRef, setBankRef] = useState('');

  // Reopening on a different payment must not inherit the last one's figure.
  useEffect(() => {
    if (open) {
      setReceived(prefill);
      setBankRef('');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, payment.id]);

  const stated = received.trim() !== '';

  let receivedLaari: number | null = null;
  try {
    const parsed = parseMvrToLaari(received);
    receivedLaari = parsed >= 1 ? parsed : null;
  } catch {
    receivedLaari = null;
  }

  // Nothing typed is a valid answer — the claim funds it, exactly as it did
  // before this field existed. Something unparseable is not.
  const invalid = stated && receivedLaari === null;
  // What the batch will actually be funded with, for the §7 verdict.
  const funding = receivedLaari ?? payment.amount_laari;

  const differs =
    receivedLaari !== null && receivedLaari !== payment.amount_laari;

  const confirm = () => {
    if (invalid) {
      return;
    }
    void onConfirm(receivedLaari, bankRef.trim() === '' ? null : bankRef.trim()).then(
      () => setOpen(false),
      () => undefined,
    );
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size={size} disabled={matching}>
          <CircleCheck />
          {matching ? 'Matching…' : 'Match payment'}
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Match payment #{payment.id}</DialogTitle>
          <DialogDescription>
            Matching allocates whole transactions oldest-first (§7). What
            allocates is what the BANK sent — the merchant&apos;s typed amount
            is a claim, and it is kept on the row either way.
          </DialogDescription>
        </DialogHeader>
        <DialogBody>
          <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-0.5 rounded-lg border border-border p-3">
              <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Claimed by the merchant
              </span>
              <span className="flex flex-wrap items-center gap-2">
                <MoneyText
                  laari={payment.amount_laari}
                  className="text-base font-semibold"
                />
                <DiscrepancyBadge row={payment} />
              </span>
              {payment.bank_ref ? (
                <span className="mt-1 font-mono text-xs text-muted-foreground">
                  {payment.bank_ref}
                </span>
              ) : null}
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor={`match-received-${payment.id}`}>
                Amount received (MVR)
              </Label>
              <Input
                id={`match-received-${payment.id}`}
                value={received}
                onChange={(event) => setReceived(event.target.value)}
                inputMode="decimal"
                placeholder="e.g. 4,300.00"
                disabled={matching}
                className="tabular-nums"
              />
              {invalid ? (
                <p className="text-sm text-destructive">
                  Enter a valid positive MVR amount, e.g. 4,300.00.
                </p>
              ) : differs ? (
                <p className="text-xs text-yellow-700 dark:text-yellow-500">
                  Not the {formatMoney(payment.amount_laari)} claimed. That is
                  fine — the statement is the money, and this figure is what
                  allocates.
                </p>
              ) : (
                <p className="text-xs text-muted-foreground">
                  Read it off the statement line. Leave it blank if you have no
                  bank figure — the row then records none, and the claim funds
                  the batch as it always did.
                </p>
              )}
            </div>

            {payment.bank_ref ? null : (
              <div className="flex flex-col gap-2">
                <Label htmlFor={`match-ref-${payment.id}`}>
                  Bank reference (from the statement)
                </Label>
                <Input
                  id={`match-ref-${payment.id}`}
                  value={bankRef}
                  onChange={(event) => setBankRef(event.target.value)}
                  placeholder="e.g. BLAZ204399156496"
                  disabled={matching}
                  className="font-mono"
                />
                <p className="text-xs text-muted-foreground">
                  The merchant quoted none. Without a reference this credit is
                  named nowhere, so the same transfer can be spent again on a
                  wallet top-up or an order.
                </p>
              </div>
            )}

            {!invalid ? (
              <ClaimVerdict amountLaari={funding} outstanding={outstanding} />
            ) : null}
          </div>
        </DialogBody>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={matching}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={confirm}
            disabled={matching || invalid}
          >
            {matching
              ? 'Matching…'
              : receivedLaari === null
                ? `Match ${formatMoney(payment.amount_laari)} (as claimed)`
                : `Match ${formatMoney(receivedLaari)}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
