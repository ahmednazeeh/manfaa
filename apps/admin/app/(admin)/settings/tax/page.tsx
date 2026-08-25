'use client';

import { useEffect, useState } from 'react';
import {
  getAdminTaxSettings,
  parsePercentToBp,
  percentToBp,
  updateAdminTaxSettings,
  type FeeTreatment,
  type TaxIdentityField,
  type TaxSettings,
  type UpdateTaxSettingsRequest,
} from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Info, ShieldAlert, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage, apiFieldErrors } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import {
  gstIdentityFieldLabel,
  gstRateBounds,
  gstTreatmentChoices,
  splitFeeForGst,
} from '@/lib/gst';
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
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardHeading,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { PageHeader } from '@/components/admin/page-header';
import { useAdminUser } from '@/components/auth/admin-guard';

const TAX_QUERY_KEY = ['admin', 'tax-settings'] as const;

/**
 * MVR 10.00 — the fee every worked example on this page is computed on, so
 * the rate dialog and the treatment card describe the same money. Integer
 * laari with ceiling rounding, exactly as the server splits it.
 */
const EXAMPLE_FEE_LAARI = 1000;

/**
 * GST on the platform fee — the registration, the rate, which side of the
 * fee the tax sits on, and the one switch that starts charging.
 *
 * Superadmin only. The API lets any admin READ the row, but throwing this
 * switch changes what every merchant owes on every sale from that moment,
 * so it is filed with the other money settings behind the same gate the
 * platform's bank accounts carry.
 *
 * FOUR THINGS THIS SCREEN HAS TO SAY OUT LOUD, because the server enforces
 * them and a form that hides them just turns a rule into a 422:
 *
 *  1. Enabling needs an identity. A GST-registered platform issues tax
 *     invoices, and an invoice that cannot name the registrant is not one.
 *     The switch stays disabled, naming exactly which of TIN / business
 *     name / activity number is still blank.
 *  2. Nothing here touches a sale that already exists. Every transaction
 *     carries the rate and treatment it was priced under, and every
 *     settlement, report and journal reads that stamp.
 *  3. Enabling announces itself, ONCE. A rate edit or a treatment switch
 *     sends nothing, so this screen must never offer to re-notify.
 *  4. `enabled_at` is the instant charging STARTED — stamped on the
 *     transition, not on every save.
 *
 * Wire grammar (PLAN §1): the rate travels as `gst_rate_percent`, a
 * 2-decimal percent STRING. Basis points are computed here for the worked
 * example and never sent.
 */
export default function TaxSettingsPage() {
  const me = useAdminUser();
  const isSuperadmin = me.role === 'superadmin';

  const query = useQuery({
    queryKey: TAX_QUERY_KEY,
    queryFn: ({ signal }) => getAdminTaxSettings({ signal }),
    enabled: isSuperadmin,
  });

  if (!isSuperadmin) {
    // Display only — EnsureSuperadmin 403s the write regardless.
    return (
      <div className="flex flex-col">
        <PageHeader title="GST" />
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <ShieldAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>Superadmin only</AlertTitle>
            <AlertDescription>
              Switching GST on changes what every merchant owes on every sale
              from that moment, so the tax registration and its switch take
              the superadmin role.
            </AlertDescription>
          </AlertContent>
        </Alert>
      </div>
    );
  }

  const data = query.data?.data;

  return (
    <div className="flex flex-col">
      <PageHeader
        title={
          <>
            GST
            {data ? (
              <Badge
                variant={data.gst_enabled ? 'success' : 'secondary'}
                appearance="light"
              >
                {data.gst_enabled ? 'Charging' : 'Not charging'}
              </Badge>
            ) : null}
          </>
        }
        description="Goods and services tax on the PLATFORM FEE — never on the customer’s cashback. The rate and treatment here price new sales only: every sale already recorded keeps the terms it was priced under, so nothing on a past settlement, report or journal moves."
      />

      {query.isPending ? (
        <div className="flex flex-col gap-5">
          <Skeleton className="h-48 w-full" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : data ? (
        <div className="flex flex-col gap-5">
          <ChargingCard settings={data} />
          <RegistrationCard settings={data} />
          <RateCard settings={data} />
          <TreatmentCard settings={data} />
        </div>
      ) : null}
    </div>
  );
}

/**
 * The one mutation every card on this page shares. Each card keeps its OWN
 * error text so a refusal is shown beside the control that caused it rather
 * than as a page-wide banner, and field errors from Laravel's validator are
 * kept separate so an input can be marked invalid on its own.
 */
function useSaveTaxSettings(successMessage: string) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const mutation = useMutation({
    mutationFn: (body: UpdateTaxSettingsRequest) => updateAdminTaxSettings(body),
    onMutate: () => {
      setError(null);
      setFieldErrors({});
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: TAX_QUERY_KEY });
      toast.success(successMessage);
    },
    onError: (cause) => {
      // The server's own words. The 422 that guards the switch is prose
      // written for a person ("GST cannot be enabled without the details a
      // tax invoice must carry: …"), so it is shown verbatim rather than
      // replaced with a friendlier guess.
      setError(apiErrorMessage(cause));
      setFieldErrors(apiFieldErrors(cause));
      toast.error(apiErrorMessage(cause));
    },
  });

  return { mutation, error, fieldErrors, clearError: () => setError(null) };
}

/** The server's refusal, shown in the card that caused it. */
function RefusalAlert({ message }: { message: string | null }) {
  if (message === null) {
    return null;
  }
  return (
    <Alert variant="destructive" appearance="light" size="sm">
      <AlertIcon>
        <TriangleAlert />
      </AlertIcon>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}

/**
 * The switch itself, with the guard the server enforces stated before the
 * click, and a confirmation that names what actually happens.
 */
function ChargingCard({ settings }: { settings: TaxSettings }) {
  const { mutation, error } = useSaveTaxSettings('GST setting saved.');
  // Which state the confirmation is for: true = start charging, false = stop.
  const [pendingChange, setPendingChange] = useState<boolean | null>(null);

  const missing = settings.missing_identity_fields;
  const blocked = !settings.gst_enabled && !settings.can_enable;
  const treatment = gstTreatmentChoices.find(
    (choice) => choice.value === settings.fee_treatment,
  );

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>Charging GST</CardTitle>
        </CardHeading>
        {settings.gst_enabled && settings.enabled_at !== null ? (
          <span className="text-sm text-muted-foreground">
            Charging since {formatDateTime(settings.enabled_at)}
          </span>
        ) : null}
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <div className="flex items-start justify-between gap-6">
          <div className="max-w-2xl">
            <p className="text-sm font-medium">
              Charge {settings.gst_rate_percent}% GST on the platform fee
            </p>
            <p className="text-sm text-muted-foreground">
              {settings.gst_enabled ? (
                <>
                  Every sale recorded from now on is priced with GST —{' '}
                  {treatment?.merchantEffect}. Sales recorded before the switch
                  was thrown keep the terms they were priced under.
                </>
              ) : (
                <>
                  With this off, no sale carries GST and the platform fee is
                  the whole of what a merchant owes on top of the cashback.
                  Switching it on starts pricing NEW sales with tax — it never
                  restates one already recorded.
                </>
              )}
            </p>
          </div>
          <Switch
            aria-label="Charge GST on the platform fee"
            checked={settings.gst_enabled}
            disabled={mutation.isPending || blocked}
            onCheckedChange={(next) => setPendingChange(next)}
          />
        </div>

        {blocked ? (
          <Alert variant="warning" appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>
                GST cannot be switched on until the registration is complete
              </AlertTitle>
              <AlertDescription>
                A tax invoice has to name who issued it. Still blank:{' '}
                {missing.map(gstIdentityFieldLabel).join(', ')}. Fill them in
                and save them below — the switch unlocks once the saved row
                names all three.
              </AlertDescription>
            </AlertContent>
          </Alert>
        ) : null}

        <RefusalAlert message={error} />
      </CardContent>

      <AlertDialog
        open={pendingChange !== null}
        onOpenChange={(open) => {
          if (!open) {
            setPendingChange(null);
          }
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pendingChange === true
                ? `Start charging ${settings.gst_rate_percent}% GST?`
                : 'Stop charging GST?'}
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              {pendingChange === true ? (
                <div className="flex flex-col gap-2 text-start">
                  <p>
                    <strong>New sales are priced with GST from now on.</strong>{' '}
                    Every sale recorded after you confirm carries{' '}
                    {settings.gst_rate_percent}% on its platform fee —{' '}
                    {treatment?.merchantEffect}.
                  </p>
                  <p>
                    <strong>Existing sales are untouched.</strong> Each one
                    keeps the rate and treatment it was priced under — no
                    settlement, report or journal entry is restated, and
                    nothing already owed changes.
                  </p>
                  <p>
                    <strong>Merchants are told once.</strong> Every approved
                    merchant’s settlement staff — trading or suspended — gets
                    a “GST now applies” notification at this moment and only
                    at this moment; a later rate change or treatment switch
                    sends nothing.
                  </p>
                </div>
              ) : (
                <div className="flex flex-col gap-2 text-start">
                  <p>
                    New sales stop carrying GST from now on. Sales already
                    priced with GST keep it: nothing is refunded, reversed or
                    restated, and the tax already collected stays a liability
                    until it is paid.
                  </p>
                  <p>
                    Merchants are <strong>not</strong> notified when charging
                    stops. Switching back on later notifies them again and
                    re-stamps the date charging resumed.
                  </p>
                </div>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              variant={pendingChange === true ? undefined : 'destructive'}
              onClick={() => {
                if (pendingChange !== null) {
                  mutation.mutate({ gst_enabled: pendingChange });
                }
                setPendingChange(null);
              }}
            >
              {pendingChange === true
                ? 'Start charging GST'
                : 'Stop charging GST'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}

/**
 * TIN, business name, activity number — the three a tax invoice must be able
 * to name. Saved together, because the switch above is waiting on all three
 * and a half-filled registration unlocks nothing.
 */
function RegistrationCard({ settings }: { settings: TaxSettings }) {
  const { mutation, error, fieldErrors } = useSaveTaxSettings(
    'Tax registration saved.',
  );

  const [form, setForm] = useState({
    gst_tin: settings.gst_tin ?? '',
    gst_business_name: settings.gst_business_name ?? '',
    gst_activity_number: settings.gst_activity_number ?? '',
  });

  // Re-sync when another superadmin's write lands via refetch.
  useEffect(() => {
    setForm({
      gst_tin: settings.gst_tin ?? '',
      gst_business_name: settings.gst_business_name ?? '',
      gst_activity_number: settings.gst_activity_number ?? '',
    });
  }, [
    settings.gst_tin,
    settings.gst_business_name,
    settings.gst_activity_number,
  ]);

  const dirty =
    form.gst_tin !== (settings.gst_tin ?? '') ||
    form.gst_business_name !== (settings.gst_business_name ?? '') ||
    form.gst_activity_number !== (settings.gst_activity_number ?? '');

  const fields: {
    key: TaxIdentityField;
    label: string;
    placeholder: string;
    hint: string;
  }[] = [
    {
      key: 'gst_tin',
      label: 'GST TIN',
      placeholder: '1000000GST501',
      hint: 'The taxpayer identification number MIRA issued for GST.',
    },
    {
      key: 'gst_business_name',
      label: 'Registered business name',
      placeholder: 'Manfaa Pvt Ltd',
      hint: 'The name on the registration, not the brand — a tax invoice names the registrant.',
    },
    {
      key: 'gst_activity_number',
      label: 'Tax activity number',
      placeholder: '001',
      hint: 'The registered activity the fee income is filed under.',
    },
  ];

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>Tax registration</CardTitle>
        </CardHeading>
        <Badge
          variant={settings.can_enable ? 'success' : 'warning'}
          appearance="light"
          size="sm"
        >
          {settings.can_enable ? 'Complete' : 'Incomplete'}
        </Badge>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <p className="max-w-2xl text-sm text-muted-foreground">
          Who the tax is charged by. These three identify Manfaa on every tax
          invoice a merchant is issued, and GST cannot be switched on until all
          three are saved.
        </p>

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {fields.map((field) => (
            <div key={field.key} className="flex flex-col gap-1.5">
              <Label htmlFor={`tax-${field.key}`}>{field.label}</Label>
              <Input
                id={`tax-${field.key}`}
                value={form[field.key]}
                placeholder={field.placeholder}
                aria-invalid={fieldErrors[field.key] !== undefined}
                onChange={(event) =>
                  setForm({ ...form, [field.key]: event.target.value })
                }
              />
              {fieldErrors[field.key] !== undefined ? (
                <p className="text-xs text-destructive">
                  {fieldErrors[field.key]}
                </p>
              ) : (
                <p className="text-xs text-muted-foreground">{field.hint}</p>
              )}
            </div>
          ))}
        </div>

        <RefusalAlert message={error} />

        <div className="flex items-center gap-3">
          <Button
            disabled={!dirty || mutation.isPending}
            onClick={() =>
              mutation.mutate({
                // Blank means "not supplied yet", which is null on the wire —
                // an empty string would look like a filled-in registration.
                gst_tin: form.gst_tin.trim() === '' ? null : form.gst_tin.trim(),
                gst_business_name:
                  form.gst_business_name.trim() === ''
                    ? null
                    : form.gst_business_name.trim(),
                gst_activity_number:
                  form.gst_activity_number.trim() === ''
                    ? null
                    : form.gst_activity_number.trim(),
              })
            }
          >
            {mutation.isPending ? 'Saving…' : 'Save registration'}
          </Button>
          {dirty ? (
            <span className="text-xs text-muted-foreground">
              Unsaved. The switch above reads the saved row, not this form.
            </span>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * The rate, as a 2-decimal percent. Zero is legal and is NOT the same thing
 * as switched off — it is what a rate looks like while the registration is
 * pending.
 */
function RateCard({ settings }: { settings: TaxSettings }) {
  const { mutation, error, fieldErrors } = useSaveTaxSettings('GST rate saved.');
  const bounds = gstRateBounds();

  const [raw, setRaw] = useState(settings.gst_rate_percent);

  useEffect(() => setRaw(settings.gst_rate_percent), [settings.gst_rate_percent]);

  // The lenient REQUEST grammar the server accepts — "8", "8.5", "8.00" —
  // read as integer basis points purely to bound-check before the 422. What
  // is SENT is the text the operator typed; basis points never leave here.
  const parsed = parsePercentToBp(raw.trim());
  const outOfRange =
    parsed !== null && (parsed < bounds.minBp || parsed > bounds.maxBp);
  const invalid = parsed === null || outOfRange;
  const dirty = raw.trim() !== settings.gst_rate_percent;

  /**
   * A rate edit WHILE CHARGING carries the switch's own blast radius — it
   * changes what every merchant owes on every sale from the next click —
   * and, unlike the switch, it notifies nobody. So it is confirmed the same
   * way, with the before/after worked on the same MVR 10.00 fee the
   * treatment card uses. While GST is off the rate moves no money and saves
   * straight through.
   */
  const [confirming, setConfirming] = useState(false);
  const needsConfirmation = settings.gst_enabled;

  const save = () => {
    if (parsed !== null) {
      mutation.mutate({ gst_rate_percent: raw.trim() });
    }
  };

  const currentBp = percentToBp(settings.gst_rate_percent);
  const before = splitFeeForGst(EXAMPLE_FEE_LAARI, currentBp, settings.fee_treatment);
  const after = splitFeeForGst(
    EXAMPLE_FEE_LAARI,
    parsed ?? currentBp,
    settings.fee_treatment,
  );

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>GST rate</CardTitle>
        </CardHeading>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
          <p className="max-w-2xl text-sm text-muted-foreground">
            Applied to the platform fee alone — the customer’s cashback is
            never taxed. Saving a new rate prices sales from that moment
            onward; every sale already recorded keeps the rate it was priced
            under, and merchants are not notified about a rate change.
          </p>
          <div className="flex shrink-0 items-start gap-2">
            <div className="flex flex-col gap-1">
              <div className="relative">
                <Input
                  id="gst-rate"
                  aria-label="GST rate, percent"
                  inputMode="decimal"
                  className="w-28 pe-8"
                  value={raw}
                  aria-invalid={invalid || fieldErrors.gst_rate_percent !== undefined}
                  onChange={(event) => setRaw(event.target.value)}
                />
                <span className="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">
                  %
                </span>
              </div>
              {parsed === null ? (
                <p className="text-xs text-destructive">
                  Enter a percentage, e.g. 8.00.
                </p>
              ) : outOfRange ? (
                <p className="text-xs text-destructive">
                  Must be between {bounds.minLabel} and {bounds.maxLabel}.
                </p>
              ) : fieldErrors.gst_rate_percent !== undefined ? (
                <p className="text-xs text-destructive">
                  {fieldErrors.gst_rate_percent}
                </p>
              ) : null}
            </div>
            <Button
              disabled={!dirty || invalid || mutation.isPending}
              onClick={() => (needsConfirmation ? setConfirming(true) : save())}
            >
              {mutation.isPending ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </div>

        <p className="text-xs text-muted-foreground/80">
          Allowed {bounds.minLabel} – {bounds.maxLabel}. 0.00% is legal and is
          not the same thing as switched off: it is what the rate looks like
          while a registration is still pending.
        </p>

        {settings.gst_enabled ? (
          <Alert variant="warning" appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              GST is live. Saving a different rate changes what every merchant
              owes on sales made from that moment — and sends no notification,
              so tell them yourself.
            </AlertDescription>
          </Alert>
        ) : null}

        <RefusalAlert message={error} />
      </CardContent>

      <AlertDialog
        open={confirming}
        onOpenChange={(open) => {
          if (!open) {
            setConfirming(false);
          }
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Charge {raw.trim()}% GST instead of {settings.gst_rate_percent}%?
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="flex flex-col gap-2 text-start">
                <p>
                  <strong>Every sale from now on is priced at the new rate.</strong>{' '}
                  On a {formatMoney(EXAMPLE_FEE_LAARI)} platform fee the
                  merchant pays {formatMoney(before.merchantPays)} today and{' '}
                  {formatMoney(after.merchantPays)} after you save; MIRA gets{' '}
                  {formatMoney(after.gst)} and Manfaa keeps{' '}
                  {formatMoney(after.net)}.
                </p>
                <p>
                  <strong>Existing sales are untouched.</strong> Each keeps the
                  rate it was priced under — nothing already owed changes.
                </p>
                <p>
                  <strong>No merchant is notified.</strong> The “GST now
                  applies” message fires once, when charging starts, and never
                  on a rate change — so tell them yourself.
                </p>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                save();
                setConfirming(false);
              }}
            >
              Save the rate
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}

/**
 * Which side of the fee the tax sits on, as an explicit two-option choice —
 * never a toggle, because the two options do opposite things to opposite
 * parties and neither is an "off" state.
 */
function TreatmentCard({ settings }: { settings: TaxSettings }) {
  const { mutation, error } = useSaveTaxSettings('Fee treatment saved.');

  const [choice, setChoice] = useState<FeeTreatment>(settings.fee_treatment);

  useEffect(() => setChoice(settings.fee_treatment), [settings.fee_treatment]);

  const dirty = choice !== settings.fee_treatment;
  const rateBp = percentToBp(settings.gst_rate_percent);

  /**
   * A treatment switch WHILE CHARGING moves real money in one click and
   * notifies nobody: `on_top` → `inclusive` cuts Manfaa's fee revenue by the
   * tax, `inclusive` → `on_top` raises every merchant's bill by it. Same
   * blast radius as the switch itself, so the same confirmation — with the
   * before/after worked on the same fee.
   */
  const [confirming, setConfirming] = useState(false);
  const before = splitFeeForGst(EXAMPLE_FEE_LAARI, rateBp, settings.fee_treatment);
  const after = splitFeeForGst(EXAMPLE_FEE_LAARI, rateBp, choice);

  // The same MVR 10.00 fee under both rules, computed the way the server
  // computes it: integer laari, ceiling rounding, per line.
  const exampleFee = EXAMPLE_FEE_LAARI;

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>How the fee carries GST</CardTitle>
        </CardHeading>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <p className="max-w-2xl text-sm text-muted-foreground">
          Both options collect the same tax for MIRA. They differ in who pays
          it: the merchant, or Manfaa out of its own fee income.
        </p>

        <RadioGroup
          value={choice}
          onValueChange={(next) => setChoice(next as FeeTreatment)}
          className="gap-0 divide-y divide-border rounded-md border border-border"
        >
          {gstTreatmentChoices.map((option) => {
            const split = splitFeeForGst(exampleFee, rateBp, option.value);
            const selected = choice === option.value;

            return (
              <label
                key={option.value}
                htmlFor={`treatment-${option.value}`}
                className={
                  selected
                    ? 'flex cursor-pointer items-start gap-3 bg-accent/40 p-4'
                    : 'flex cursor-pointer items-start gap-3 p-4'
                }
              >
                <RadioGroupItem
                  id={`treatment-${option.value}`}
                  value={option.value}
                  className="mt-0.5"
                />
                <div className="flex flex-col gap-1">
                  <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {option.title}
                    {settings.fee_treatment === option.value ? (
                      <Badge variant="info" appearance="light" size="sm">
                        In use
                      </Badge>
                    ) : null}
                  </span>
                  <span className="text-sm text-muted-foreground">
                    {option.help}
                  </span>
                  <span className="text-xs text-muted-foreground/80">
                    {rateBp === 0 ? (
                      <>
                        At 0.00% the two are identical: a{' '}
                        {formatMoney(exampleFee)} fee is{' '}
                        {formatMoney(exampleFee)} to the merchant and{' '}
                        {formatMoney(exampleFee)} of revenue.
                      </>
                    ) : (
                      <>
                        At {settings.gst_rate_percent}%, on a{' '}
                        {formatMoney(exampleFee)} fee: the merchant pays{' '}
                        <strong>{formatMoney(split.merchantPays)}</strong>, MIRA
                        gets {formatMoney(split.gst)}, Manfaa keeps{' '}
                        {formatMoney(split.net)}.
                      </>
                    )}
                  </span>
                </div>
              </label>
            );
          })}
        </RadioGroup>

        {dirty && settings.gst_enabled ? (
          <Alert variant="warning" appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              GST is live. Changing this changes what merchants owe on sales
              made from the moment you save — sales already recorded keep the
              treatment they were priced under, and no notification is sent.
            </AlertDescription>
          </Alert>
        ) : null}

        <RefusalAlert message={error} />

        <Separator />

        <div className="flex flex-wrap items-center gap-3">
          <Button
            disabled={!dirty || mutation.isPending}
            onClick={() =>
              settings.gst_enabled
                ? setConfirming(true)
                : mutation.mutate({ fee_treatment: choice })
            }
          >
            {mutation.isPending ? 'Saving…' : 'Save treatment'}
          </Button>
          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Info className="size-3.5 shrink-0" />
            Currently in use: {settings.fee_treatment_label}.
          </span>
        </div>
      </CardContent>

      <AlertDialog
        open={confirming}
        onOpenChange={(open) => {
          if (!open) {
            setConfirming(false);
          }
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Switch to “{gstTreatmentChoices.find((o) => o.value === choice)?.title}”?
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="flex flex-col gap-2 text-start">
                <p>
                  <strong>Who pays the tax changes on the next sale.</strong>{' '}
                  On a {formatMoney(EXAMPLE_FEE_LAARI)} platform fee the
                  merchant pays {formatMoney(before.merchantPays)} today and{' '}
                  {formatMoney(after.merchantPays)} after you save; Manfaa
                  keeps {formatMoney(before.net)} today and{' '}
                  {formatMoney(after.net)} after.
                </p>
                <p>
                  <strong>Existing sales are untouched.</strong> Each keeps the
                  treatment it was priced under — no settlement, report or
                  journal entry is restated.
                </p>
                <p>
                  <strong>No merchant is notified.</strong> The “GST now
                  applies” message fires once, when charging starts, and never
                  on a treatment switch — so tell them yourself.
                </p>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                mutation.mutate({ fee_treatment: choice });
                setConfirming(false);
              }}
            >
              Switch the treatment
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}
