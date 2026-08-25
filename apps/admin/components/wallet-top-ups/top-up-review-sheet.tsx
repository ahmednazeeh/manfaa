'use client';

import { useEffect, useState, type ReactNode } from 'react';
import {
  matchWalletTopUp,
  parseMvrToLaari,
  rejectWalletTopUp,
  type TransferSettingsResponse,
  type WalletTopUp,
} from '@manfaa/api-client';
import { formatMoney, MoneyText } from '@manfaa/ui';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Ban, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { topUpSlipPath } from '@/lib/slip';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { BankLabel } from '@/components/admin/bank-select';
import { SlipFrame } from '@/components/admin/slip-frame';
import { TopUpStateBadge } from '@/components/admin/state-badge';
import {
  DiscrepancyBadge,
  DiscrepancyNote,
} from '@/components/transfers/claim-and-fact';
import { autoVerifyStatus } from './auto-verify';
import { AutoVerifyBadge, autoVerifyExplanation } from './auto-verify-badge';

const MIN_REASON = 3;
const MAX_REASON = 1000;
const MAX_BANK_REF = 128;

/**
 * What the admin typed into the received field, as integer laari — or null
 * when it is not a usable amount. The string never passes through a float:
 * `parseMvrToLaari` decomposes it, and it throws rather than guessing.
 */
function receivedLaari(input: string): number | null {
  try {
    const laari = parseMvrToLaari(input);
    return laari >= 1 ? laari : null;
  } catch {
    return null;
  }
}

export const WALLET_TOP_UPS_QUERY_KEY = ['admin', 'wallet-top-ups'] as const;

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-b border-border py-2.5 last:border-b-0">
      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      <span className="text-sm text-foreground">{children}</span>
    </div>
  );
}

/**
 * The heart of the top-up queue: one merchant's evidence for one claimed
 * transfer — the slip, the bank reference, the account they say they paid
 * into — beside the two review outcomes. Match credits the wallet through
 * the SAME path the verifier uses (never a second one); Reject records a
 * reason the merchant reads verbatim and releases the reference so the claim
 * can be made again once sorted.
 *
 * A decided row stays readable here after the fact, which is what makes the
 * matched and rejected tabs worth having.
 */
export function TopUpReviewSheet({
  topUp,
  settings,
  onClose,
  onDecided,
}: {
  topUp: WalletTopUp | null;
  settings: TransferSettingsResponse['data'] | undefined;
  onClose: () => void;
  /** The row as the server returned it after Match or Reject. */
  onDecided: (decided: WalletTopUp) => void;
}) {
  return (
    <Sheet
      open={topUp !== null}
      onOpenChange={(open) => (open ? null : onClose())}
    >
      <SheetContent className="sm:max-w-2xl">
        {topUp ? (
          <ReviewBody
            key={topUp.id}
            topUp={topUp}
            settings={settings}
            onDecided={onDecided}
          />
        ) : null}
      </SheetContent>
    </Sheet>
  );
}

function ReviewBody({
  topUp,
  settings,
  onDecided,
}: {
  topUp: WalletTopUp;
  settings: TransferSettingsResponse['data'] | undefined;
  onDecided: (decided: WalletTopUp) => void;
}) {
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<'idle' | 'reject'>('idle');
  const [bankRef, setBankRef] = useState('');
  // WHAT THE STATEMENT SAYS ARRIVED (owner, 2026-08-25). Deliberately EMPTY
  // rather than prefilled with the merchant's claim (verifier round,
  // 2026-08-25): this figure is credited to a wallet as spendable money on
  // one click, and a prefill puts a blind credit of an amount that may never
  // have arrived behind a button that reads "Credit MVR 20.00". The reviewer
  // is holding the statement line — they type what it says, and the claim is
  // named beside the field to compare against. Nothing is guessed for them.
  const [received, setReceived] = useState('');
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  // A stable "now" for the watch-window verdict; re-read once a minute so a
  // row left open flips from "watching" to "not found" on its own.
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 60_000);
    return () => window.clearInterval(timer);
  }, []);

  const status = autoVerifyStatus(topUp, settings, now);
  const explanation = autoVerifyExplanation(status);
  const isPending = topUp.state === 'pending';
  const needsBankRef = topUp.bank_ref === null;

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: WALLET_TOP_UPS_QUERY_KEY });

  const match = useMutation({
    mutationFn: (laari: number) =>
      matchWalletTopUp(topUp.id, {
        received_laari: laari,
        ...(needsBankRef ? { bank_ref: bankRef.trim() } : {}),
      }),
    onSuccess: (response) => {
      invalidate();
      onDecided(response.data);
      const credited =
        response.data.received_laari ?? response.data.amount_laari;
      toast.success(
        `${formatMoney(credited)} credited to ${
          response.data.merchant?.name ?? 'the merchant'
        }'s wallet.${
          response.data.amount_differs
            ? ` The merchant had typed ${formatMoney(response.data.amount_laari)} — the bank's figure is what was credited.`
            : ''
        }`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const reject = useMutation({
    mutationFn: (trimmed: string) =>
      rejectWalletTopUp(topUp.id, { reason: trimmed }),
    onSuccess: (response) => {
      invalidate();
      onDecided(response.data);
      setMode('idle');
      setReason('');
      toast.success(
        'Rejected — nothing was credited and the merchant can claim the transfer again once it is sorted.',
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const busy = match.isPending || reject.isPending;
  const bankRefMissing = needsBankRef && bankRef.trim() === '';
  const bankRefTooLong = bankRef.trim().length > MAX_BANK_REF;
  const receivedValue = receivedLaari(received);
  const receivedDiffers =
    receivedValue !== null && receivedValue !== topUp.amount_laari;

  const submitReject = () => {
    const trimmed = reason.trim();
    if (trimmed.length < MIN_REASON) {
      setReasonError(
        'A reason is required — the merchant reads it verbatim before claiming again.',
      );
      return;
    }
    if (trimmed.length > MAX_REASON) {
      setReasonError(`At most ${MAX_REASON} characters.`);
      return;
    }
    setReasonError(null);
    reject.mutate(trimmed);
  };

  const merchantName = topUp.merchant?.name ?? `Merchant #${topUp.merchant_id}`;
  const otherRefs = topUp.matched_trx_refs.filter(
    (ref) => ref !== topUp.matched_trx_id,
  );

  return (
    <>
      <SheetHeader>
        <SheetTitle className="flex flex-wrap items-center gap-2">
          {merchantName}
          <TopUpStateBadge state={topUp.state} />
        </SheetTitle>
        <SheetDescription>
          Wallet top-up #{topUp.id} · {formatMoney(topUp.amount_laari)} claimed{' '}
          {formatDateTime(topUp.created_at)}
        </SheetDescription>
      </SheetHeader>

      <SheetBody className="grow p-0">
        <ScrollArea className="h-full px-5 py-4">
          <div className="flex flex-col gap-5">
            {topUp.state === 'rejected' ? (
              <Alert variant="destructive" appearance="light">
                <AlertIcon>
                  <Ban />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>
                    Claim rejected {formatDateTime(topUp.rejected_at)}
                  </AlertTitle>
                  <AlertDescription>
                    <p className="whitespace-pre-wrap">
                      {topUp.rejected_reason}
                    </p>
                    <p className="mt-1.5 text-xs">
                      Nothing was credited. The bank reference was released, so
                      the merchant can claim this transfer again with a
                      corrected slip.
                    </p>
                  </AlertDescription>
                </AlertContent>
              </Alert>
            ) : null}

            {topUp.state === 'matched' ? (
              <Alert variant="success" appearance="light">
                <AlertIcon>
                  <CircleCheck />
                </AlertIcon>
                <AlertContent>
                  <AlertTitle>
                    Wallet credited {formatDateTime(topUp.matched_at)}
                  </AlertTitle>
                  <AlertDescription>
                    {/* What actually went in — the bank's figure where there
                        is one, the claim only where nobody ever had a better
                        number. Printing the claim on a row the bank
                        contradicted would put a number in the audit trail
                        that no money matches. */}
                    {formatMoney(topUp.received_laari ?? topUp.amount_laari)} is
                    now balance
                    {topUp.wallet_transaction_id !== null
                      ? ` — wallet movement #${topUp.wallet_transaction_id}`
                      : ''}
                    . {explanation}
                    {topUp.amount_differs ? (
                      <p className="mt-1.5">
                        <DiscrepancyNote
                          row={topUp}
                          credited="credited to the wallet"
                        />
                      </p>
                    ) : null}
                  </AlertDescription>
                </AlertContent>
              </Alert>
            ) : null}

            <SlipFrame
              path={topUp.has_slip ? topUpSlipPath(topUp.id) : null}
              sizeBytes={topUp.slip_size_bytes}
              alt={`Transfer slip for ${merchantName}'s ${formatMoney(topUp.amount_laari)} top-up`}
              empty={{
                title: 'No slip on this claim',
                hint: 'Verify the bank reference against the statement before matching.',
              }}
            />

            <div className="flex flex-col">
              <Fact label="Merchant">
                {merchantName}
                {topUp.merchant?.bank_account_name ? (
                  <span className="ms-2 text-xs text-muted-foreground">
                    pays as {topUp.merchant.bank_account_name}
                  </span>
                ) : null}
              </Fact>
              {/* THE CLAIM AND THE FACT, side by side. The claim is what the
                  merchant typed and is never rewritten; the received figure
                  is the bank's, and is the one that became balance. */}
              <Fact label="Amount claimed">
                <MoneyText
                  laari={topUp.amount_laari}
                  className={
                    topUp.amount_differs
                      ? 'text-base text-muted-foreground'
                      : 'text-base font-semibold'
                  }
                />
                {topUp.amount_differs ? (
                  <span className="ms-2 text-xs text-muted-foreground">
                    what the merchant typed
                  </span>
                ) : null}
              </Fact>
              <Fact label="Amount received">
                {topUp.received_laari === null ? (
                  <span className="text-muted-foreground">
                    {topUp.state === 'pending'
                      ? 'Not yet known — the bank has not been matched to this claim.'
                      : 'Not recorded on this claim.'}
                  </span>
                ) : (
                  <span className="flex flex-wrap items-center gap-2">
                    <MoneyText
                      laari={topUp.received_laari}
                      className="text-base font-semibold"
                    />
                    <DiscrepancyBadge row={topUp} />
                  </span>
                )}
              </Fact>
              <Fact label="Paid into">
                {topUp.platform_bank_account ? (
                  <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <BankLabel
                      bank={topUp.platform_bank_account.bank_name}
                      className="font-medium"
                    />
                    <span dir="ltr">
                      {topUp.platform_bank_account.account_no}
                    </span>
                    <span className="text-muted-foreground">
                      {topUp.platform_bank_account.account_name}
                    </span>
                  </span>
                ) : (
                  <span className="text-muted-foreground">
                    No account named — the merchant claimed before the choice
                    was offered.
                  </span>
                )}
              </Fact>
              <Fact label="Bank reference (merchant's)">
                <span className="font-mono text-sm">
                  {topUp.bank_ref ?? '—'}
                </span>
                {topUp.bank_ref === null ? (
                  <span className="ms-2 text-xs text-muted-foreground">
                    none typed — the slip carries it
                  </span>
                ) : null}
              </Fact>
              {topUp.matched_trx_id !== null ? (
                <Fact label="Bank reference (bank's)">
                  <span className="font-mono text-sm">
                    {topUp.matched_trx_id}
                  </span>
                  {otherRefs.map((ref) => (
                    <span
                      key={ref}
                      className="ms-2 font-mono text-xs text-muted-foreground"
                    >
                      {ref}
                    </span>
                  ))}
                  {topUp.matched_payer_name ? (
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      paid by {topUp.matched_payer_name}
                    </span>
                  ) : null}
                </Fact>
              ) : null}
              <Fact label="Submitted">{formatDateTime(topUp.created_at)}</Fact>
              <Fact label="Automatic verification">
                <span className="flex flex-wrap items-center gap-2">
                  <AutoVerifyBadge status={status} />
                  {status.kind === 'unknown' ? (
                    <span className="text-xs text-muted-foreground">
                      Reading transfer settings…
                    </span>
                  ) : null}
                </span>
                {explanation && topUp.state === 'pending' ? (
                  <span className="mt-1 block text-xs text-muted-foreground">
                    {explanation}
                  </span>
                ) : null}
              </Fact>
            </div>

            {isPending ? (
              <div className="flex flex-col gap-4 rounded-lg border border-border p-4">
                <Alert variant="info" appearance="light" size="sm">
                  <AlertIcon>
                    <Info />
                  </AlertIcon>
                  <AlertDescription>
                    Matching credits{' '}
                    {receivedValue === null
                      ? 'the amount you enter below'
                      : formatMoney(receivedValue)}{' '}
                    to the merchant&apos;s wallet at once — the figure on the
                    STATEMENT, not the one they typed. While the store keeps
                    auto-settle on, the hourly run settles validated cashback
                    from that balance oldest-first, as much as fits. The
                    reference below becomes the movement&apos;s idempotency key,
                    so one transfer can never be credited twice.
                  </AlertDescription>
                </Alert>

                {mode === 'idle' ? (
                  <>
                    <div className="flex flex-col gap-2">
                      <Label htmlFor={`top-up-received-${topUp.id}`}>
                        Amount received (MVR, required)
                      </Label>
                      <Input
                        id={`top-up-received-${topUp.id}`}
                        value={received}
                        onChange={(event) => setReceived(event.target.value)}
                        inputMode="decimal"
                        placeholder="e.g. 1,234.56"
                        disabled={busy}
                        className="tabular-nums"
                      />
                      {received.trim() !== '' && receivedValue === null ? (
                        <p className="text-sm text-destructive">
                          Enter a valid positive MVR amount, e.g. 1,234.56.
                        </p>
                      ) : receivedDiffers ? (
                        <p className="text-xs text-yellow-700 dark:text-yellow-500">
                          Not the {formatMoney(topUp.amount_laari)} the merchant
                          typed. That is fine — the statement is the money and
                          this figure is what will be credited. Their claim is
                          kept on the row either way.
                        </p>
                      ) : (
                        <p className="text-xs text-muted-foreground">
                          Read it off the statement line. The merchant claimed{' '}
                          {formatMoney(topUp.amount_laari)} — that is what they
                          typed, not what arrived, so nothing is filled in for
                          you here.
                        </p>
                      )}
                    </div>

                    {needsBankRef ? (
                      <div className="flex flex-col gap-2">
                        <Label htmlFor={`top-up-ref-${topUp.id}`}>
                          Bank reference (required)
                        </Label>
                        <Input
                          id={`top-up-ref-${topUp.id}`}
                          value={bankRef}
                          onChange={(event) => setBankRef(event.target.value)}
                          placeholder="As printed on the slip or the statement"
                          maxLength={MAX_BANK_REF}
                          disabled={busy}
                          className="font-mono"
                        />
                        <p className="text-xs text-muted-foreground">
                          The merchant typed none. Read it off the slip or the
                          bank statement — a credit without a reference could be
                          booked twice.
                        </p>
                      </div>
                    ) : null}

                    <div className="flex flex-wrap items-center gap-2">
                      <Button
                        disabled={
                          busy ||
                          bankRefMissing ||
                          bankRefTooLong ||
                          receivedValue === null
                        }
                        onClick={() =>
                          receivedValue === null
                            ? undefined
                            : match.mutate(receivedValue)
                        }
                      >
                        <CircleCheck />
                        {match.isPending
                          ? 'Matching…'
                          : receivedValue === null
                            ? 'Match and credit wallet'
                            : `Credit ${formatMoney(receivedValue)}`}
                      </Button>
                      <Button
                        variant="outline"
                        className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        disabled={busy}
                        onClick={() => setMode('reject')}
                      >
                        <Ban />
                        Reject
                      </Button>
                    </div>
                  </>
                ) : (
                  <div className="flex flex-col gap-3">
                    <Alert variant="warning" appearance="light" size="sm">
                      <AlertIcon>
                        <TriangleAlert />
                      </AlertIcon>
                      <AlertDescription>
                        Nothing is credited. The merchant sees your reason and
                        can claim the same transfer again with a corrected slip.
                        Rejecting cannot be undone.
                      </AlertDescription>
                    </Alert>
                    <div className="flex flex-col gap-2">
                      <Label htmlFor={`top-up-reason-${topUp.id}`}>
                        Reason (required)
                      </Label>
                      <Textarea
                        id={`top-up-reason-${topUp.id}`}
                        rows={4}
                        maxLength={MAX_REASON}
                        placeholder="e.g. The slip shows MVR 500.00 but the claim is MVR 5,000.00, and no transfer of either amount reached the account."
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        disabled={busy}
                      />
                      {reasonError !== null ? (
                        <p className="text-sm text-destructive">
                          {reasonError}
                        </p>
                      ) : (
                        <p className="text-xs text-muted-foreground">
                          The merchant reads this word-for-word.
                        </p>
                      )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <Button
                        variant="destructive"
                        disabled={busy}
                        onClick={submitReject}
                      >
                        {reject.isPending ? 'Rejecting…' : 'Reject claim'}
                      </Button>
                      <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => {
                          setMode('idle');
                          setReasonError(null);
                        }}
                      >
                        Keep reviewing
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            ) : null}
          </div>
        </ScrollArea>
      </SheetBody>
    </>
  );
}
