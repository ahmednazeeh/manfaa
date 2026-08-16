'use client';

import { useMemo, useRef, useState, type ReactNode } from 'react';
import {
  parsePercentToBp,
  percentToBp,
  type MerchantChannel,
  type MerchantSetupState,
  type StoreCategoryOption,
} from '@manfaa/api-client';
import {
  Check,
  ImageIcon,
  LoaderCircle,
  TriangleAlert,
  Upload,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { formatBp, formatRate, trimRate } from '@/lib/estimate';
import {
  merchantChannelHint,
  merchantChannelLabel,
  SETUP_MISSING_KEYS,
  setupMissingLabel,
  type SetupMissingKey,
} from '@/lib/labels';
import {
  apiErrorMessage,
  onboardingErrorCode,
  setupMissingKeys,
  useSubmitSetup,
  useUpdateSetupProfile,
  useUpdateSetupRate,
  useUploadSetupLogo,
} from '@/lib/queries';
import { cn } from '@/lib/utils';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { formatPin } from '@/components/app/location-picker';
import {
  locationRequired,
  LocationStep,
  pinnedLocation,
} from '@/components/setup/location-step';

/**
 * The resumable store setup wizard (§1 decision 2026-08-15). Six steps —
 * Store profile (curated category + channel), Location (the primary branch's
 * map pin; required for a store with a counter, skippable online), Logo
 * (skippable), Cashback rate (percent input via parsePercentToBp with the
 * all-in preview), Terms & exclusions, Review & submit. Every step is
 * persisted server-side the moment it saves, so quitting mid-wizard resumes
 * exactly where the owner left off (GET /merchant/setup).
 *
 * STEPS is the only place a step's position is written down. Every jump —
 * resume, the "fix this" links on the review step, each row's Edit button —
 * resolves through STEPS.indexOf, so inserting or reordering a step here is
 * the whole change rather than the first of a dozen.
 */

const STEPS = [
  'profile',
  'location',
  'logo',
  'rate',
  'terms',
  'review',
] as const;
type StepId = (typeof STEPS)[number];

const CHANNELS: readonly MerchantChannel[] = ['in_store', 'online', 'both'];

/**
 * §4 static fee bands for the pre-submit all-in preview. The platform's fee
 * tier schedule is authoritative — the server prices the actual fee and
 * refuses unpriced rates (rate_not_priced).
 */
const STATIC_FEE_BANDS: ReadonlyArray<{ to: number; fee: number }> = [
  { to: 99, fee: 25 },
  { to: 199, fee: 50 },
  { to: 499, fee: 75 },
  { to: 2000, fee: 100 },
];

function staticFeeBp(rateBp: number): number {
  const band = STATIC_FEE_BANDS.find(({ to }) => rateBp <= to);
  return (band ?? STATIC_FEE_BANDS[STATIC_FEE_BANDS.length - 1]).fee;
}

/** Resume at the first step whose REQUIRED value is still missing. */
function firstIncompleteStep(state: MerchantSetupState): number {
  if (!state.steps.profile || state.values.category === null) {
    return STEPS.indexOf('profile');
  }
  // An online-only store may pass this step unpinned, so being unpinned does
  // not make it incomplete — only a store customers can walk into.
  if (locationRequired(state) && pinnedLocation(state) === null) {
    return STEPS.indexOf('location');
  }
  if (state.values.cashback_rate_percent === null) return STEPS.indexOf('rate');
  if ((state.values.eligibility_basis ?? '').trim() === '') {
    return STEPS.indexOf('terms');
  }
  return STEPS.length - 1;
}

function categoryLabel(
  category: StoreCategoryOption,
  language: string,
): string {
  return language === 'dv' && category.name_dv !== null
    ? category.name_dv
    : category.name_en;
}

// ---------------------------------------------------------------------------
// Step indicator
// ---------------------------------------------------------------------------

function StepIndicator({
  current,
  onNavigate,
}: {
  current: number;
  onNavigate: (index: number) => void;
}) {
  const { t } = useTranslation();

  const labels: Record<StepId, string> = {
    profile: t('setup.stepProfile'),
    location: t('setup.stepLocation'),
    logo: t('setup.stepLogo'),
    rate: t('setup.stepRate'),
    terms: t('setup.stepTerms'),
    review: t('setup.stepReview'),
  };

  return (
    <nav aria-label={t('setup.title')}>
      <p className="text-xs text-muted-foreground mb-2 sm:hidden">
        {t('setup.progress', { current: current + 1, total: STEPS.length })}
      </p>
      <ol className="flex items-center gap-1.5">
        {STEPS.map((id, index) => {
          const done = index < current;
          const active = index === current;
          return (
            <li key={id} className="flex items-center gap-1.5 min-w-0">
              <button
                type="button"
                aria-current={active ? 'step' : undefined}
                onClick={() => onNavigate(index)}
                className={cn(
                  'flex items-center gap-2 cursor-pointer rounded-full',
                  'focus-visible:outline-2 focus-visible:outline-primary',
                )}
              >
                <span
                  className={cn(
                    'size-6 rounded-full text-xs font-medium inline-flex items-center justify-center shrink-0',
                    active
                      ? 'bg-primary text-primary-foreground'
                      : done
                        ? 'bg-primary/15 text-primary'
                        : 'bg-muted text-muted-foreground',
                  )}
                >
                  {done ? <Check className="size-3.5" /> : index + 1}
                </span>
                <span
                  className={cn(
                    'text-xs hidden sm:inline truncate',
                    active
                      ? 'text-foreground font-medium'
                      : 'text-muted-foreground',
                  )}
                >
                  {labels[id]}
                </span>
              </button>
              {index < STEPS.length - 1 && (
                <span className="w-4 h-px bg-border shrink-0" aria-hidden />
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

// ---------------------------------------------------------------------------
// Step 1 — store profile (curated category + channel + contact)
// ---------------------------------------------------------------------------

function ProfileStep({
  state,
  onDone,
}: {
  state: MerchantSetupState;
  onDone: () => void;
}) {
  const { t, i18n } = useTranslation();
  const updateProfile = useUpdateSetupProfile();

  const [category, setCategory] = useState<string | null>(
    state.values.category,
  );
  const [channel, setChannel] = useState<MerchantChannel>(state.values.channel);
  const [contactEmail, setContactEmail] = useState(
    state.values.contact_email ?? '',
  );
  const [contactPhone, setContactPhone] = useState(
    state.values.contact_phone ?? '',
  );
  const [categoryError, setCategoryError] = useState(false);

  const save = () => {
    if (category === null) {
      setCategoryError(true);
      return;
    }
    updateProfile.mutate(
      {
        category,
        channel,
        contact_email: contactEmail.trim() === '' ? null : contactEmail.trim(),
        contact_phone: contactPhone.trim() === '' ? null : contactPhone.trim(),
      },
      {
        onSuccess: onDone,
        onError: (error) => {
          toast.error(
            onboardingErrorCode(error) === 'setup_not_editable'
              ? t('setup.notEditable')
              : apiErrorMessage(error, t('common.serverUnreachable')),
          );
        },
      },
    );
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('setup.profileTitle')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-6">
        <div className="flex flex-col gap-2.5">
          <Label>{t('setup.categoryLabel')}</Label>
          <p className="text-xs text-muted-foreground">
            {t('setup.categoryHint')}
          </p>
          <div
            className="flex flex-wrap gap-2"
            role="listbox"
            aria-label={t('setup.categoryLabel')}
          >
            {state.categories.map((option) => {
              const selected = option.slug === category;
              return (
                <button
                  key={option.slug}
                  type="button"
                  role="option"
                  aria-selected={selected}
                  onClick={() => {
                    setCategory(option.slug);
                    setCategoryError(false);
                  }}
                  className={cn(
                    'px-3 py-1.5 rounded-full border text-sm cursor-pointer transition-colors',
                    selected
                      ? 'border-primary bg-primary/10 text-primary font-medium'
                      : 'border-border text-secondary-foreground hover:border-primary/50',
                  )}
                >
                  {categoryLabel(option, i18n.language)}
                </button>
              );
            })}
          </div>
          {categoryError && (
            <p className="text-xs text-destructive">
              {t('setup.categoryRequired')}
            </p>
          )}
        </div>

        <div className="flex flex-col gap-2.5">
          <Label>{t('setup.channelLabel')}</Label>
          <RadioGroup
            value={channel}
            onValueChange={(value) => setChannel(value as MerchantChannel)}
            className="grid grid-cols-1 sm:grid-cols-3 gap-2.5"
          >
            {CHANNELS.map((option) => (
              <label
                key={option}
                className={cn(
                  'flex items-start gap-2.5 rounded-md border p-3 cursor-pointer transition-colors',
                  channel === option
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/50',
                )}
              >
                <RadioGroupItem value={option} className="mt-0.5" />
                <span className="flex flex-col gap-0.5">
                  <span className="text-sm font-medium">
                    {merchantChannelLabel(t, option)}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {merchantChannelHint(t, option)}
                  </span>
                </span>
              </label>
            ))}
          </RadioGroup>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="setup-contact-email">
              {t('setup.contactEmailLabel')}
            </Label>
            <Input
              id="setup-contact-email"
              type="email"
              maxLength={255}
              value={contactEmail}
              onChange={(event) => setContactEmail(event.target.value)}
            />
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="setup-contact-phone">
              {t('setup.contactPhoneLabel')}
            </Label>
            <Input
              id="setup-contact-phone"
              dir="ltr"
              maxLength={32}
              value={contactPhone}
              onChange={(event) => setContactPhone(event.target.value)}
            />
          </div>
          <p className="text-xs text-muted-foreground sm:col-span-2">
            {t('setup.contactHint')}
          </p>
        </div>
      </CardContent>
      <CardFooter className="justify-end">
        <Button disabled={updateProfile.isPending} onClick={save}>
          {updateProfile.isPending && <LoaderCircle className="animate-spin" />}
          {t('common.continue')}
        </Button>
      </CardFooter>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Step 2 — logo (skippable)
// ---------------------------------------------------------------------------

const LOGO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const LOGO_MAX_BYTES = 2 * 1024 * 1024;

export function LogoUploader({
  currentUrl,
  upload,
  uploading,
  onUploaded,
}: {
  currentUrl: string | null;
  upload: (file: File, onSuccess: () => void) => void;
  uploading: boolean;
  onUploaded?: () => void;
}) {
  const { t } = useTranslation();
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);

  const pick = (picked: File | null) => {
    setFileError(null);
    if (picked === null) return;
    if (!LOGO_TYPES.includes(picked.type)) {
      setFileError(t('setup.logoInvalidType'));
      return;
    }
    if (picked.size > LOGO_MAX_BYTES) {
      setFileError(t('setup.logoTooLarge'));
      return;
    }
    setFile(picked);
    setPreviewUrl((previous) => {
      if (previous !== null) URL.revokeObjectURL(previous);
      return URL.createObjectURL(picked);
    });
  };

  const shownUrl = previewUrl ?? currentUrl;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-5">
        <div className="size-24 rounded-lg border border-border bg-muted/40 flex items-center justify-center overflow-hidden shrink-0">
          {shownUrl !== null ? (
            <img
              src={shownUrl}
              alt={
                previewUrl !== null
                  ? t('setup.logoPreviewAlt')
                  : t('setup.logoCurrentAlt')
              }
              className="size-full object-contain"
            />
          ) : (
            <ImageIcon className="size-8 text-muted-foreground" />
          )}
        </div>
        <div className="flex flex-col gap-2.5">
          <input
            ref={inputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(event) => pick(event.target.files?.[0] ?? null)}
          />
          <div className="flex flex-wrap gap-2.5">
            <Button
              type="button"
              variant="outline"
              onClick={() => inputRef.current?.click()}
            >
              {shownUrl !== null
                ? t('setup.logoReplace')
                : t('setup.logoChoose')}
            </Button>
            {file !== null && (
              <Button
                type="button"
                disabled={uploading}
                onClick={() =>
                  upload(file, () => {
                    setFile(null);
                    onUploaded?.();
                  })
                }
              >
                {uploading ? (
                  <LoaderCircle className="animate-spin" />
                ) : (
                  <Upload />
                )}
                {t('setup.logoUpload')}
              </Button>
            )}
          </div>
          <p className="text-xs text-muted-foreground max-w-sm">
            {t('setup.logoHint')}
          </p>
        </div>
      </div>
      {fileError !== null && (
        <p className="text-xs text-destructive">{fileError}</p>
      )}
    </div>
  );
}

function LogoStep({
  state,
  onDone,
  onBack,
}: {
  state: MerchantSetupState;
  onDone: () => void;
  onBack: () => void;
}) {
  const { t } = useTranslation();
  const uploadLogo = useUploadSetupLogo();
  const [uploadedUrl, setUploadedUrl] = useState<string | null>(null);

  const upload = (file: File, onSuccess: () => void) => {
    uploadLogo.mutate(file, {
      onSuccess: (response) => {
        setUploadedUrl(response.data.logo_url);
        toast.success(t('setup.logoUploaded'));
        onSuccess();
      },
      onError: (error) => {
        toast.error(
          onboardingErrorCode(error) === 'setup_not_editable'
            ? t('setup.notEditable')
            : apiErrorMessage(error, t('common.serverUnreachable')),
        );
      },
    });
  };

  const hasLogo = (uploadedUrl ?? state.values.logo_url) !== null;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('setup.logoTitle')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {t('setup.logoSubtitle')}
        </p>
        <LogoUploader
          currentUrl={uploadedUrl ?? state.values.logo_url}
          upload={upload}
          uploading={uploadLogo.isPending}
        />
      </CardContent>
      <CardFooter className="justify-between">
        <Button variant="ghost" onClick={onBack}>
          {t('common.back')}
        </Button>
        <Button
          variant={hasLogo ? 'primary' : 'outline'}
          disabled={uploadLogo.isPending}
          onClick={onDone}
        >
          {hasLogo ? t('common.continue') : t('common.skip')}
        </Button>
      </CardFooter>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Step 3 — cashback rate (percent input, all-in preview)
// ---------------------------------------------------------------------------

function RateStep({
  state,
  onDone,
  onBack,
}: {
  state: MerchantSetupState;
  onDone: () => void;
  onBack: () => void;
}) {
  const { t } = useTranslation();
  const updateRate = useUpdateSetupRate();

  // The API states the bounds and the saved rate as 2-decimal percent
  // strings (PLAN §1). This step compares them against what the owner types
  // and adds a fee to them, so it reads them as integer bp once, here.
  const currentPercent = state.values.cashback_rate_percent;
  const minBp = percentToBp(state.rate_bounds.min_percent);
  const maxBp = percentToBp(state.rate_bounds.max_percent);
  const currentBp = currentPercent === null ? null : percentToBp(currentPercent);

  const [input, setInput] = useState('');
  const [notPriced, setNotPriced] = useState<string | null>(null);

  const trimmed = input.trim();
  const typedBp = trimmed === '' ? null : parsePercentToBp(trimmed);

  const inputError =
    trimmed === ''
      ? null
      : typedBp === null
        ? t('setup.rateFormatError')
        : typedBp < minBp
          ? t('setup.rateMinError', {
              min: formatRate(state.rate_bounds.min_percent),
            })
          : typedBp > maxBp
            ? t('setup.rateMaxError', {
                max: formatRate(state.rate_bounds.max_percent),
              })
            : null;

  const validTypedBp = inputError === null ? typedBp : null;
  // The preview shows the typed rate as soon as it is valid; otherwise the
  // already-saved rate (resuming owners see their saved choice). A saved
  // rate is shown as the server's own percent; a typed one as typed.
  const previewBp = validTypedBp ?? currentBp;
  const previewRate =
    validTypedBp !== null
      ? formatBp(validTypedBp)
      : currentPercent === null
        ? null
        : formatRate(currentPercent);
  const previewFee = previewBp === null ? null : staticFeeBp(previewBp);

  const canContinue =
    !updateRate.isPending &&
    (validTypedBp !== null || (trimmed === '' && currentBp !== null));

  const save = () => {
    // Nothing typed but a rate already saved — plain navigation.
    if (validTypedBp === null) {
      if (trimmed === '' && currentBp !== null) onDone();
      return;
    }
    if (validTypedBp === currentBp) {
      onDone();
      return;
    }
    setNotPriced(null);
    updateRate.mutate(validTypedBp, {
      onSuccess: () => {
        toast.success(t('setup.rateSaved'));
        onDone();
      },
      onError: (error) => {
        if (onboardingErrorCode(error) === 'rate_not_priced') {
          setNotPriced(apiErrorMessage(error, t('setup.rateNotPricedTitle')));
        } else if (onboardingErrorCode(error) === 'setup_not_editable') {
          toast.error(t('setup.notEditable'));
        } else {
          toast.error(apiErrorMessage(error, t('common.serverUnreachable')));
        }
      },
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('setup.rateTitle')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <p className="text-sm text-muted-foreground">
          {t('setup.rateSubtitle')}
        </p>

        <div className="flex flex-col gap-2.5">
          <Label htmlFor="setup-rate">{t('setup.rateLabel')}</Label>
          <InputGroup className="w-56">
            <Input
              id="setup-rate"
              inputMode="decimal"
              dir="ltr"
              placeholder={
                currentPercent === null
                  ? t('setup.ratePlaceholder')
                  : trimRate(currentPercent)
              }
              value={input}
              aria-invalid={inputError !== null}
              onChange={(event) => {
                setInput(event.target.value);
                setNotPriced(null);
              }}
            />
            <InputAddon>%</InputAddon>
          </InputGroup>
          {inputError !== null ? (
            <p className="text-xs text-destructive">{inputError}</p>
          ) : (
            <p className="text-xs text-muted-foreground">
              {t('setup.rateRangeHint', {
                min: formatRate(state.rate_bounds.min_percent),
                max: formatRate(state.rate_bounds.max_percent),
              })}
            </p>
          )}
        </div>

        {previewBp !== null && previewRate !== null && previewFee !== null && (
          <div className="rounded-md border border-border p-4">
            <div className="grid grid-cols-3 gap-4">
              <div>
                <div className="text-xs text-muted-foreground">
                  {t('setup.ratePreviewCashback')}
                </div>
                <div className="text-xl font-semibold">{previewRate}</div>
              </div>
              <div>
                <div className="text-xs text-muted-foreground">
                  {t('setup.ratePreviewFee')}
                </div>
                <div className="text-xl font-semibold">
                  {formatBp(previewFee)}
                </div>
              </div>
              <div>
                <div className="text-xs text-muted-foreground">
                  {t('setup.ratePreviewAllIn')}
                </div>
                <div className="text-xl font-semibold">
                  {formatBp(previewBp + previewFee)}
                </div>
              </div>
            </div>
            <p className="text-xs text-muted-foreground mt-3">
              {t('setup.ratePreviewNote')}
            </p>
          </div>
        )}

        {notPriced !== null && (
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>{t('setup.rateNotPricedTitle')}</AlertTitle>
              <AlertDescription>{notPriced}</AlertDescription>
            </AlertContent>
          </Alert>
        )}
      </CardContent>
      <CardFooter className="justify-between">
        <Button variant="ghost" onClick={onBack}>
          {t('common.back')}
        </Button>
        <Button disabled={!canContinue} onClick={save}>
          {updateRate.isPending && <LoaderCircle className="animate-spin" />}
          {t('common.continue')}
        </Button>
      </CardFooter>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Step 4 — terms & exclusions
// ---------------------------------------------------------------------------

function TermsStep({
  state,
  onDone,
  onBack,
}: {
  state: MerchantSetupState;
  onDone: () => void;
  onBack: () => void;
}) {
  const { t } = useTranslation();
  const updateProfile = useUpdateSetupProfile();

  const [terms, setTerms] = useState(state.values.eligibility_basis ?? '');
  const [termsError, setTermsError] = useState(false);

  const save = () => {
    if (terms.trim() === '') {
      setTermsError(true);
      return;
    }
    updateProfile.mutate(
      { eligibility_basis: terms },
      {
        onSuccess: onDone,
        onError: (error) => {
          toast.error(
            onboardingErrorCode(error) === 'setup_not_editable'
              ? t('setup.notEditable')
              : apiErrorMessage(error, t('common.serverUnreachable')),
          );
        },
      },
    );
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('setup.termsTitle')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {t('setup.termsSubtitle')}
        </p>
        <div className="flex flex-col gap-2.5">
          <Label htmlFor="setup-terms">{t('setup.termsLabel')}</Label>
          <Textarea
            id="setup-terms"
            rows={5}
            maxLength={2000}
            value={terms}
            placeholder={t('setup.termsPlaceholder')}
            aria-invalid={termsError}
            onChange={(event) => {
              setTerms(event.target.value);
              setTermsError(false);
            }}
          />
          {termsError ? (
            <p className="text-xs text-destructive">
              {t('setup.termsRequired')}
            </p>
          ) : (
            <p className="text-xs text-muted-foreground">
              {t('setup.termsGuidance')}
            </p>
          )}
        </div>
      </CardContent>
      <CardFooter className="justify-between">
        <Button variant="ghost" onClick={onBack}>
          {t('common.back')}
        </Button>
        <Button disabled={updateProfile.isPending} onClick={save}>
          {updateProfile.isPending && <LoaderCircle className="animate-spin" />}
          {t('common.continue')}
        </Button>
      </CardFooter>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Step 5 — review & submit
// ---------------------------------------------------------------------------

const MISSING_KEYS = SETUP_MISSING_KEYS;

function ReviewStep({
  state,
  onBack,
  onNavigate,
}: {
  state: MerchantSetupState;
  onBack: () => void;
  onNavigate: (index: number) => void;
}) {
  const { t, i18n } = useTranslation();
  const submitSetup = useSubmitSetup();
  const [missing, setMissing] = useState<string[]>([]);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const values = state.values;
  const pin = pinnedLocation(state);
  const category = state.categories.find(
    (option) => option.slug === values.category,
  );
  const ratePercent = values.cashback_rate_percent;
  const rateBp = ratePercent === null ? null : percentToBp(ratePercent);
  const feeBp = rateBp === null ? null : staticFeeBp(rateBp);

  const submit = () => {
    setMissing([]);
    setSubmitError(null);
    submitSetup.mutate(undefined, {
      onError: (error) => {
        const missingKeys = setupMissingKeys(error);
        if (missingKeys.length > 0) {
          setMissing(missingKeys);
        } else if (onboardingErrorCode(error) === 'setup_not_editable') {
          setSubmitError(t('setup.notEditable'));
        } else {
          setSubmitError(apiErrorMessage(error, t('common.serverUnreachable')));
        }
      },
    });
  };

  /** The wizard step that fixes a missing requirement key. */
  const stepForMissing: Record<SetupMissingKey, number> = {
    category: STEPS.indexOf('profile'),
    channel: STEPS.indexOf('profile'),
    rate: STEPS.indexOf('rate'),
    terms: STEPS.indexOf('terms'),
  };

  const row = (label: string, value: ReactNode, editIndex: number) => (
    <div className="flex items-start justify-between gap-4 py-2.5 border-b border-border last:border-b-0">
      <div className="min-w-0">
        <div className="text-xs text-muted-foreground">{label}</div>
        <div className="text-sm break-words">{value}</div>
      </div>
      <Button
        variant="ghost"
        size="sm"
        className="shrink-0"
        onClick={() => onNavigate(editIndex)}
      >
        {t('setup.edit')}
      </Button>
    </div>
  );

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('setup.reviewTitle')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {t('setup.reviewSubtitle')}
        </p>

        <div className="flex flex-col">
          {row(t('setup.reviewName'), values.name, STEPS.indexOf('profile'))}
          {row(
            t('setup.reviewCategory'),
            category !== undefined ? (
              categoryLabel(category, i18n.language)
            ) : (
              <span className="text-muted-foreground">
                {t('common.notSet')}
              </span>
            ),
            STEPS.indexOf('profile'),
          )}
          {row(
            t('setup.reviewChannel'),
            merchantChannelLabel(t, values.channel),
            STEPS.indexOf('profile'),
          )}
          {row(
            t('setup.reviewLocation'),
            pin !== null ? (
              <span className="tabular-nums">{formatPin(pin)}</span>
            ) : (
              <span className="text-muted-foreground">
                {t('setup.reviewNoLocation')}
              </span>
            ),
            STEPS.indexOf('location'),
          )}
          {row(
            t('setup.reviewLogo'),
            values.logo_url !== null ? (
              <img
                src={values.logo_url}
                alt={t('setup.logoCurrentAlt')}
                className="size-12 rounded-md border border-border object-contain mt-1"
              />
            ) : (
              <span className="text-muted-foreground">
                {t('setup.reviewNoLogo')}
              </span>
            ),
            STEPS.indexOf('logo'),
          )}
          {row(
            t('setup.reviewRate'),
            ratePercent !== null && rateBp !== null && feeBp !== null ? (
              <span dir="ltr">
                {formatRate(ratePercent)} · {t('setup.reviewFee')}{' '}
                {formatBp(feeBp)} · {t('setup.reviewAllIn')}{' '}
                {formatBp(rateBp + feeBp)}
              </span>
            ) : (
              <span className="text-muted-foreground">
                {t('common.notSet')}
              </span>
            ),
            STEPS.indexOf('rate'),
          )}
          {row(
            t('setup.reviewTerms'),
            (values.eligibility_basis ?? '').trim() !== '' ? (
              <span className="whitespace-pre-wrap">
                {values.eligibility_basis}
              </span>
            ) : (
              <span className="text-muted-foreground">
                {t('common.notSet')}
              </span>
            ),
            STEPS.indexOf('terms'),
          )}
        </div>

        {missing.length > 0 && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>{t('setup.missingTitle')}</AlertTitle>
              <AlertDescription>
                <ul className="list-disc ms-4 flex flex-col gap-1">
                  {MISSING_KEYS.filter((key) => missing.includes(key)).map(
                    (key) => (
                      <li key={key}>
                        <button
                          type="button"
                          className="underline cursor-pointer"
                          onClick={() => onNavigate(stepForMissing[key])}
                        >
                          {setupMissingLabel(t, key)}
                        </button>
                      </li>
                    ),
                  )}
                </ul>
              </AlertDescription>
            </AlertContent>
          </Alert>
        )}

        {submitError !== null && (
          <Alert variant="destructive" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertTitle>{submitError}</AlertTitle>
          </Alert>
        )}

        <p className="text-xs text-muted-foreground">
          {t('setup.reviewSubmitNote')}
        </p>
      </CardContent>
      <CardFooter className="justify-between">
        <Button variant="ghost" onClick={onBack}>
          {t('common.back')}
        </Button>
        <Button disabled={submitSetup.isPending} onClick={submit}>
          {submitSetup.isPending && <LoaderCircle className="animate-spin" />}
          {t('setup.submit')}
        </Button>
      </CardFooter>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// The wizard shell
// ---------------------------------------------------------------------------

export function SetupWizard({ state }: { state: MerchantSetupState }) {
  const { t } = useTranslation();
  // Resume where the owner left off; navigation is free afterwards — every
  // step is server-persisted and submit re-validates completeness anyway.
  const [step, setStep] = useState<number>(() => firstIncompleteStep(state));

  const rejectionBanner = useMemo(
    () =>
      state.status === 'rejected' && state.rejected_reason !== null ? (
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>{t('setup.rejectedTitle')}</AlertTitle>
            <AlertDescription className="whitespace-pre-wrap">
              {state.rejected_reason}
            </AlertDescription>
          </AlertContent>
        </Alert>
      ) : null,
    [state.status, state.rejected_reason, t],
  );

  const next = () => setStep((value) => Math.min(value + 1, STEPS.length - 1));
  const back = () => setStep((value) => Math.max(value - 1, 0));

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1.5">
        <h1 className="text-lg font-semibold text-mono">{t('setup.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('setup.subtitle')}</p>
      </div>

      <StepIndicator current={step} onNavigate={setStep} />

      {rejectionBanner}

      {STEPS[step] === 'profile' && <ProfileStep state={state} onDone={next} />}
      {STEPS[step] === 'location' && (
        <LocationStep state={state} onDone={next} onBack={back} />
      )}
      {STEPS[step] === 'logo' && (
        <LogoStep state={state} onDone={next} onBack={back} />
      )}
      {STEPS[step] === 'rate' && (
        <RateStep state={state} onDone={next} onBack={back} />
      )}
      {STEPS[step] === 'terms' && (
        <TermsStep state={state} onDone={next} onBack={back} />
      )}
      {STEPS[step] === 'review' && (
        <ReviewStep state={state} onBack={back} onNavigate={setStep} />
      )}
    </div>
  );
}
