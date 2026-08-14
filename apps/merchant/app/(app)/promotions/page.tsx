'use client';

import { useMemo, useState } from 'react';
import {
  bpToPercentString,
  parseMvrToLaari,
  parsePercentToBp,
  type CreatePromotionRequest,
  type Promotion,
  type PromotionCostPreview,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { format } from 'date-fns';
import { LoaderCircle, Plus, TriangleAlert, X } from 'lucide-react';
import { formatBp } from '@/lib/estimate';
import {
  apiErrorMessage,
  isRateNotPriced,
  rateNotPricedMessage,
  useBranches,
  useCancelPromotion,
  useCreatePromotion,
  usePromotions,
  usePublishPromotion,
  useRate,
} from '@/lib/queries';
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
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { toast } from 'sonner';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';
import { PromotionStatusBadge } from '@/components/app/state-badge';

/**
 * The promotion builder (§10): a time-boxed cashback BOOST above the
 * standing rate. Staff may read the list; only the owner builds, publishes
 * or cancels (the API answers 403 otherwise, this page just hides the
 * tools). All rates are entered and shown as PERCENT — converted to integer
 * basis points with the shared exact-2dp string parser, never a float — and
 * the min-purchase / per-customer-cap amounts stay MVR.
 *
 * Once published a promotion is immutable for its stated duration: no edit,
 * no early end. The publish confirm says so in plain words.
 */

/** §4 structural bounds, integer bp. The active fee schedule may end lower. */
const MIN_RATE_BP = 50;
const MAX_RATE_BP = 2000;

/** Maldives is UTC+05:00 year-round — timestamps are sent with it explicit. */
const BUSINESS_UTC_OFFSET = '+05:00';
const BUSINESS_OFFSET_MS = 5 * 60 * 60 * 1000;

/** Now as a datetime-local string ("YYYY-MM-DDTHH:mm") in UTC+5 wall time. */
function nowLocalValue(): string {
  return new Date(Date.now() + BUSINESS_OFFSET_MS).toISOString().slice(0, 16);
}

/** "YYYY-MM-DDTHH:mm[:ss]" (business wall time) → ISO 8601 with +05:00. */
function toBusinessIso(localValue: string): string {
  const withSeconds =
    localValue.length === 16 ? `${localValue}:00` : localValue;
  return `${withSeconds}${BUSINESS_UTC_OFFSET}`;
}

/** parseMvrToLaari without the throw: null when not a valid MVR amount. */
function safeParseMvr(input: string): number | null {
  try {
    return parseMvrToLaari(input);
  } catch {
    return null;
  }
}

/** Integer bp delta as trimmed percentage points: 26 -> "+0.26pp". */
function formatPp(deltaBp: number): string {
  const sign = deltaBp > 0 ? '+' : '';
  return `${sign}${bpToPercentString(deltaBp).replace(/\.?0+$/, '')}pp`;
}

function CostPreviewAlert({ preview }: { preview: PromotionCostPreview }) {
  return (
    <Alert
      variant={preview.tier_changed ? 'warning' : 'info'}
      appearance="light"
    >
      <AlertIcon>
        <TriangleAlert />
      </AlertIcon>
      <AlertContent>
        <AlertTitle>
          During the promotion you pay {formatBp(preview.promo.all_in_bp)} of
          each eligible sale
        </AlertTitle>
        <AlertDescription>
          {formatBp(preview.promo.rate_bp)} cashback +{' '}
          {formatBp(preview.promo.fee_bp)} platform fee.
          {preview.standing !== null && preview.all_in_delta_bp !== null && (
            <>
              {' '}
              That is {formatPp(preview.all_in_delta_bp)} versus your standing
              all-in cost of {formatBp(preview.standing.all_in_bp)}.
            </>
          )}
          {preview.tier_changed && preview.standing !== null && (
            <>
              {' '}
              Boosting from {formatBp(preview.standing.rate_bp)} to{' '}
              {formatBp(preview.promo.rate_bp)} moves your fee tier from{' '}
              {formatBp(preview.standing.fee_bp)} to{' '}
              {formatBp(preview.promo.fee_bp)}.
            </>
          )}
        </AlertDescription>
      </AlertContent>
    </Alert>
  );
}

function PromotionBuilder({
  standingRateBp,
  onCreated,
  onClose,
}: {
  standingRateBp: number | null;
  onCreated: (preview: PromotionCostPreview) => void;
  onClose: () => void;
}) {
  const branches = useBranches();
  const createPromotion = useCreatePromotion();

  const [rateInput, setRateInput] = useState('');
  const [startsAt, setStartsAt] = useState(nowLocalValue());
  const [endsAt, setEndsAt] = useState('');
  const [minPurchaseInput, setMinPurchaseInput] = useState('');
  const [capInput, setCapInput] = useState('');
  const [branchId, setBranchId] = useState<string>('all');
  const [notPriced, setNotPriced] = useState<string | null>(null);

  const trimmedRate = rateInput.trim();
  const rateBp = trimmedRate === '' ? null : parsePercentToBp(trimmedRate);
  const rateError =
    trimmedRate === ''
      ? null
      : rateBp === null
        ? 'Enter a percent with up to two decimal places, e.g. 7.50.'
        : rateBp < MIN_RATE_BP || rateBp > MAX_RATE_BP
          ? `Rates run from ${formatBp(MIN_RATE_BP)} to ${formatBp(MAX_RATE_BP)}.`
          : standingRateBp !== null && rateBp <= standingRateBp
            ? `A promotion must boost above your standing rate of ${formatBp(standingRateBp)}.`
            : null;
  const validRateBp = rateError === null ? rateBp : null;

  const windowError =
    startsAt === '' || endsAt === ''
      ? null
      : endsAt <= startsAt
        ? 'The promotion must end after it starts.'
        : null;

  const minPurchaseLaari =
    minPurchaseInput.trim() === '' ? undefined : safeParseMvr(minPurchaseInput);
  const minPurchaseError =
    minPurchaseLaari === null || (minPurchaseLaari !== undefined && minPurchaseLaari < 0)
      ? 'Enter an MVR amount, e.g. 100.00, or leave empty.'
      : null;

  const capLaari = capInput.trim() === '' ? undefined : safeParseMvr(capInput);
  const capError =
    capLaari === null || (capLaari !== undefined && capLaari < 1)
      ? 'Enter an MVR amount of at least 0.01, or leave empty.'
      : null;

  const canCreate =
    validRateBp !== null &&
    startsAt !== '' &&
    endsAt !== '' &&
    windowError === null &&
    minPurchaseError === null &&
    capError === null &&
    !createPromotion.isPending;

  const create = () => {
    if (validRateBp === null || minPurchaseLaari === null || capLaari === null) {
      return;
    }
    setNotPriced(null);
    const body: CreatePromotionRequest = {
      rate_bp: validRateBp,
      starts_at: toBusinessIso(startsAt),
      ends_at: toBusinessIso(endsAt),
      ...(minPurchaseLaari !== undefined
        ? { min_purchase_laari: minPurchaseLaari }
        : {}),
      ...(capLaari !== undefined
        ? { max_cashback_per_customer_laari: capLaari }
        : {}),
      ...(branchId !== 'all' ? { branch_id: Number(branchId) } : {}),
    };
    createPromotion.mutate(body, {
      onSuccess: (response) => {
        toast.success('Draft promotion created');
        onCreated(response.cost_preview);
        onClose();
      },
      onError: (error) => {
        if (isRateNotPriced(error)) {
          setNotPriced(rateNotPricedMessage(error));
        } else {
          toast.error(
            apiErrorMessage(error, 'Could not create the promotion.'),
          );
        }
      },
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>New promotion</CardTitle>
        <Button variant="ghost" mode="icon" onClick={onClose} aria-label="Close">
          <X />
        </Button>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <div className="flex flex-col gap-2.5">
          <Label htmlFor="promo-rate">Boosted cashback rate</Label>
          <InputGroup className="w-56">
            <Input
              id="promo-rate"
              inputMode="decimal"
              placeholder="e.g. 7.50"
              value={rateInput}
              aria-invalid={rateError !== null}
              onChange={(event) => {
                setRateInput(event.target.value);
                setNotPriced(null);
              }}
            />
            <InputAddon>%</InputAddon>
          </InputGroup>
          {rateError !== null ? (
            <p className="text-xs text-destructive">{rateError}</p>
          ) : (
            <p className="text-xs text-muted-foreground">
              Percent of the eligible amount, up to two decimal places.
              {standingRateBp !== null && (
                <>
                  {' '}
                  Must be above your standing rate of{' '}
                  {formatBp(standingRateBp)}.
                </>
              )}{' '}
              A higher rate can land on a higher platform fee tier — you see
              the exact cost before publishing.
            </p>
          )}
        </div>

        <div className="flex flex-wrap gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="promo-starts">Starts</Label>
            <Input
              id="promo-starts"
              type="datetime-local"
              className="w-56"
              value={startsAt}
              onChange={(event) => setStartsAt(event.target.value)}
            />
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="promo-ends">Ends</Label>
            <Input
              id="promo-ends"
              type="datetime-local"
              className="w-56"
              value={endsAt}
              aria-invalid={windowError !== null}
              onChange={(event) => setEndsAt(event.target.value)}
            />
          </div>
        </div>
        {windowError !== null ? (
          <p className="text-xs text-destructive">{windowError}</p>
        ) : (
          <p className="text-xs text-muted-foreground">
            Maldives time. Once published, the promotion runs for exactly this
            window — it cannot be edited or ended early.
          </p>
        )}

        <div className="flex flex-wrap gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="promo-min">Minimum purchase (optional)</Label>
            <InputGroup className="w-56">
              <InputAddon>MVR</InputAddon>
              <Input
                id="promo-min"
                inputMode="decimal"
                placeholder="e.g. 100.00"
                value={minPurchaseInput}
                aria-invalid={minPurchaseError !== null}
                onChange={(event) => setMinPurchaseInput(event.target.value)}
              />
            </InputGroup>
            {minPurchaseError !== null && (
              <p className="text-xs text-destructive">{minPurchaseError}</p>
            )}
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="promo-cap">
              Per-customer cashback cap (optional)
            </Label>
            <InputGroup className="w-56">
              <InputAddon>MVR</InputAddon>
              <Input
                id="promo-cap"
                inputMode="decimal"
                placeholder="e.g. 500.00"
                value={capInput}
                aria-invalid={capError !== null}
                onChange={(event) => setCapInput(event.target.value)}
              />
            </InputGroup>
            {capError !== null && (
              <p className="text-xs text-destructive">{capError}</p>
            )}
          </div>
        </div>

        <div className="flex flex-col gap-2.5">
          <Label htmlFor="promo-branch">Branch</Label>
          <Select value={branchId} onValueChange={setBranchId}>
            <SelectTrigger id="promo-branch" className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All branches</SelectItem>
              {(branches.data ?? []).map((branch) => (
                <SelectItem key={branch.id} value={String(branch.id)}>
                  {branch.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {notPriced !== null && (
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>Rate not available</AlertTitle>
              <AlertDescription>{notPriced}</AlertDescription>
            </AlertContent>
          </Alert>
        )}
      </CardContent>
      <CardFooter className="justify-end gap-2.5">
        <Button variant="outline" onClick={onClose}>
          Discard
        </Button>
        <Button disabled={!canCreate} onClick={create}>
          {createPromotion.isPending && (
            <LoaderCircle className="animate-spin" />
          )}
          Create draft
        </Button>
      </CardFooter>
    </Card>
  );
}

function DraftActions({
  promotion,
  onPreview,
}: {
  promotion: Promotion;
  onPreview: (preview: PromotionCostPreview) => void;
}) {
  const publish = usePublishPromotion();
  const cancel = useCancelPromotion();

  const doPublish = () => {
    publish.mutate(promotion.id, {
      onSuccess: (response) => {
        toast.success('Promotion published');
        onPreview(response.cost_preview);
      },
      onError: (error) => {
        toast.error(
          isRateNotPriced(error)
            ? rateNotPricedMessage(error)
            : apiErrorMessage(error, 'Could not publish the promotion.'),
        );
      },
    });
  };

  const doCancel = () => {
    cancel.mutate(promotion.id, {
      onSuccess: () => toast.success('Draft cancelled'),
      onError: (error) =>
        toast.error(apiErrorMessage(error, 'Could not cancel the draft.')),
    });
  };

  return (
    <div className="flex justify-end gap-2">
      <Button
        size="sm"
        variant="outline"
        disabled={cancel.isPending || publish.isPending}
        onClick={doCancel}
      >
        {cancel.isPending && <LoaderCircle className="animate-spin" />}
        Cancel
      </Button>
      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button size="sm" disabled={publish.isPending || cancel.isPending}>
            {publish.isPending && <LoaderCircle className="animate-spin" />}
            Publish
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Publish the {formatBp(promotion.rate_bp)} promotion?
            </AlertDialogTitle>
            <AlertDialogDescription>
              It runs {format(new Date(promotion.starts_at), 'dd MMM, HH:mm')}{' '}
              to {format(new Date(promotion.ends_at), 'dd MMM, HH:mm')}. Once
              published it cannot be changed or ended early — customers can
              rely on the advertised boost for the whole window.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Keep as draft</AlertDialogCancel>
            <AlertDialogAction onClick={doPublish}>Publish</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

export default function PromotionsPage() {
  const { me } = useLayout();
  const isOwner = me.role === 'owner';

  const promotions = usePromotions();
  const rate = useRate();
  const branches = useBranches(isOwner);

  const [builderOpen, setBuilderOpen] = useState(false);
  const [preview, setPreview] = useState<PromotionCostPreview | null>(null);

  const branchNames = useMemo(() => {
    const names = new Map<number, string>();
    for (const branch of branches.data ?? []) {
      names.set(branch.id, branch.name);
    }
    return names;
  }, [branches.data]);

  const standingRateBp = rate.data?.current?.rate_bp ?? null;

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Promotions</ToolbarPageTitle>
          <ToolbarDescription>
            Time-boxed cashback boosts above your standing rate
          </ToolbarDescription>
        </ToolbarHeading>
        {isOwner && (
          <ToolbarActions>
            <Button
              onClick={() => setBuilderOpen(true)}
              disabled={builderOpen}
            >
              <Plus />
              New promotion
            </Button>
          </ToolbarActions>
        )}
      </Toolbar>

      <div className="flex flex-col gap-5 pb-7.5">
        {isOwner && builderOpen && (
          <PromotionBuilder
            standingRateBp={standingRateBp}
            onCreated={setPreview}
            onClose={() => setBuilderOpen(false)}
          />
        )}

        {preview !== null && <CostPreviewAlert preview={preview} />}

        {promotions.error ? (
          <ErrorBlock error={promotions.error} />
        ) : !promotions.data ? (
          <LoadingBlock lines={4} />
        ) : promotions.data.length === 0 ? (
          <Card>
            <EmptyBlock>
              No promotions yet.
              {isOwner && ' Create one to boost your cashback for a while.'}
            </EmptyBlock>
          </Card>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle>All promotions</CardTitle>
            </CardHeader>
            <CardTable>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Cashback</TableHead>
                    <TableHead>You pay</TableHead>
                    <TableHead>Window</TableHead>
                    <TableHead>Min purchase</TableHead>
                    <TableHead>Per-customer cap</TableHead>
                    <TableHead>Branch</TableHead>
                    <TableHead>Status</TableHead>
                    {isOwner && <TableHead className="text-end" />}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {promotions.data.map((promotion) => (
                    <TableRow key={promotion.id}>
                      <TableCell className="font-medium">
                        {formatBp(promotion.rate_bp)}
                      </TableCell>
                      {/* Null fee: a stale draft the current fee schedule no
                          longer prices — still listed so it can be cancelled;
                          publishing it is refused. */}
                      <TableCell className="text-secondary-foreground">
                        {promotion.all_in_bp === null
                          ? '—'
                          : formatBp(promotion.all_in_bp)}
                        <span className="text-xs text-muted-foreground">
                          {' '}
                          (fee{' '}
                          {promotion.fee_bp === null
                            ? '—'
                            : formatBp(promotion.fee_bp)}
                          )
                        </span>
                      </TableCell>
                      <TableCell className="text-secondary-foreground whitespace-nowrap">
                        {format(
                          new Date(promotion.starts_at),
                          'dd MMM, HH:mm',
                        )}{' '}
                        – {format(new Date(promotion.ends_at), 'dd MMM, HH:mm')}
                      </TableCell>
                      <TableCell>
                        {promotion.min_purchase_laari === null ? (
                          <span className="text-muted-foreground">—</span>
                        ) : (
                          <MoneyText laari={promotion.min_purchase_laari} />
                        )}
                      </TableCell>
                      <TableCell>
                        {promotion.max_cashback_per_customer_laari === null ? (
                          <span className="text-muted-foreground">—</span>
                        ) : (
                          <MoneyText
                            laari={promotion.max_cashback_per_customer_laari}
                          />
                        )}
                      </TableCell>
                      <TableCell className="text-secondary-foreground">
                        {promotion.branch_id === null
                          ? 'All branches'
                          : (branchNames.get(promotion.branch_id) ??
                            `Branch #${promotion.branch_id}`)}
                      </TableCell>
                      <TableCell>
                        <PromotionStatusBadge
                          status={promotion.status}
                          live={promotion.is_live}
                        />
                      </TableCell>
                      {isOwner && (
                        <TableCell>
                          {promotion.status === 'draft' && (
                            <DraftActions
                              promotion={promotion}
                              onPreview={setPreview}
                            />
                          )}
                        </TableCell>
                      )}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardTable>
          </Card>
        )}
      </div>
    </div>
  );
}
