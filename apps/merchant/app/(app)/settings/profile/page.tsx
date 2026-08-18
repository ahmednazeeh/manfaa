'use client';

import { useEffect, useState } from 'react';
import {
  type MerchantChannel,
  type MerchantProfile,
  type MerchantSetupState,
} from '@manfaa/api-client';
import { ExternalLink, LoaderCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { isReviewedStatus } from '@/lib/api';
import { merchantChannelLabel, merchantStatusLabel } from '@/lib/labels';
import {
  apiErrorMessage,
  useProfile,
  useSetupState,
  useUpdateProfile,
  useUploadSettingsLogo,
} from '@/lib/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CopyButton } from '@/components/settlement/copy-button';
import {
  Card,
  CardContent,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import { PendingChangeAlert } from '@/components/app/pending-change';
import { LogoUploader } from '@/components/setup/setup-wizard';

const STATUS_VARIANT = {
  draft: 'secondary',
  pending_review: 'info',
  rejected: 'warning',
  active: 'success',
  suspended: 'destructive',
  closed: 'secondary',
} as const;

const CHANNELS: readonly MerchantChannel[] = ['in_store', 'online', 'both'];

/**
 * Settings > Profile. Category is a curated pick (superadmin list — no free
 * text), channel is the in_store/online/both enum whose "both" is ALWAYS
 * displayed as "In Store & Online", and the logo uploader hits the same
 * endpoint as the setup wizard (open to active merchants).
 */
function ProfileForm({
  profile,
  setup,
}: {
  profile: MerchantProfile;
  setup: MerchantSetupState;
}) {
  const { t, i18n } = useTranslation();
  const updateProfile = useUpdateProfile();
  const uploadLogo = useUploadSettingsLogo();

  const [nameDv, setNameDv] = useState(profile.name_dv ?? '');
  const [category, setCategory] = useState(profile.category ?? '');
  const [channel, setChannel] = useState<MerchantChannel>(profile.channel);
  const [eligibilityBasis, setEligibilityBasis] = useState(
    profile.eligibility_basis ?? '',
  );
  const [contactEmail, setContactEmail] = useState(profile.contact_email ?? '');
  const [contactPhone, setContactPhone] = useState(profile.contact_phone ?? '');
  const [supportPhone, setSupportPhone] = useState(profile.support_phone ?? '');

  // The address a merchant hands to a customer. Kept whole rather than shown
  // as a bare slug: a slug is a thing they have to be told how to use, and
  // the point of the row is something they can paste into a WhatsApp
  // message. Overridable per environment so a staging panel does not send
  // people to production.
  const storeUrl = `${
    process.env.NEXT_PUBLIC_STOREFRONT_URL ?? 'https://manfaa.app'
  }/store/${profile.slug}`;
  // Derived by COMPARISON: "same as contact" is stored as an actual copy of
  // the contact number (the server materialises it), so the tick means the
  // two fields hold the same number.
  const [supportSameAsContact, setSupportSameAsContact] = useState(
    (profile.support_phone ?? '') === '' ||
      profile.support_phone === profile.contact_phone,
  );
  const [websiteUrl, setWebsiteUrl] = useState(profile.website_url ?? '');
  const [name, setName] = useState(profile.name);
  const [logoUrl, setLogoUrl] = useState(setup.values.logo_url);

  // Re-sync when a save comes back with the server's normalised values.
  useEffect(() => {
    setNameDv(profile.name_dv ?? '');
    setCategory(profile.category ?? '');
    setChannel(profile.channel);
    setEligibilityBasis(profile.eligibility_basis ?? '');
    setContactEmail(profile.contact_email ?? '');
    setContactPhone(profile.contact_phone ?? '');
    setSupportPhone(profile.support_phone ?? '');
    setSupportSameAsContact(
      (profile.support_phone ?? '') === '' ||
        profile.support_phone === profile.contact_phone,
    );
    setWebsiteUrl(profile.website_url ?? '');
    setName(profile.name);
  }, [profile]);

  const categoryOptionLabel = (slug: string): string => {
    const option = setup.categories.find((entry) => entry.slug === slug);
    if (option === undefined) {
      // A category since retired from the curated list — still shown so the
      // select reflects reality, labelled as no longer offered.
      return t('settings.categoryInactive', { name: slug });
    }
    return i18n.language === 'dv' && option.name_dv !== null
      ? option.name_dv
      : option.name_en;
  };

  const categorySlugs = setup.categories.map((entry) => entry.slug);
  const selectableSlugs =
    category !== '' && !categorySlugs.includes(category)
      ? [category, ...categorySlugs]
      : categorySlugs;

  // The superadmin retired the category this store is on. The save still
  // goes through (the server accepts the unchanged value), but the picker
  // cannot offer it any more, so say so where the picker is rather than
  // letting a 422 explain it after the fact.
  const showRetiredHint =
    profile.category_retired && category === profile.category;

  // MR9. Whether this store's public claims are reviewed before they move —
  // used to word the form, never to decide what happened: the server's 202
  // is what says a save queued.
  const reviewed = isReviewedStatus(profile.status);

  // What this store has queued for Manfaa's review. The fields above stay
  // the LIVE values on purpose (they are what a shopper reads until an admin
  // approves), so the banner is the only place the proposed values appear.
  // The logo rides the same request: the tile keeps showing the logo in
  // service and the proposal appears beside it.
  const pending = profile.pending_change;
  const pendingLogoUrl =
    typeof pending?.proposed.logo_url === 'string'
      ? pending.proposed.logo_url
      : null;

  /**
   * The diff in the same words the form uses: a category as its curated
   * name, a channel as "In Store & Online" — never the slug the column
   * holds. Anything else falls through to the banner's plain rendering.
   */
  const formatChangeValue = (field: string, value: unknown) => {
    if (typeof value !== 'string') return undefined;
    if (field === 'category') return categoryOptionLabel(value);
    if (field === 'channel' && CHANNELS.includes(value as MerchantChannel)) {
      return merchantChannelLabel(t, value as MerchantChannel);
    }
    return undefined;
  };

  const save = () => {
    // Send `category` only when it actually changed. A retired category is
    // still accepted by the server when unchanged, so this is belt and
    // braces — but it also keeps the PATCH honest: an edit to a phone
    // number should not carry a category write at all.
    const categoryChanged = category !== (profile.category ?? '');

    updateProfile.mutate(
      {
        ...(categoryChanged
          ? { category: category === '' ? null : category }
          : {}),
        name_dv: nameDv.trim() === '' ? null : nameDv.trim(),
        channel,
        eligibility_basis:
          eligibilityBasis.trim() === '' ? null : eligibilityBasis,
        contact_email: contactEmail.trim() === '' ? null : contactEmail.trim(),
        name: name.trim(),
        contact_phone: contactPhone.trim() === '' ? null : contactPhone.trim(),
        // The contact number itself, not null — the support field is always
        // materialised so the storefront always has a real number; see the
        // wizard and Merchant::booted().
        support_phone: supportSameAsContact || supportPhone.trim() === ''
          ? (contactPhone.trim() === '' ? null : contactPhone.trim())
          : supportPhone.trim(),
        website_url: websiteUrl.trim() === '' ? null : websiteUrl.trim(),
      },
      {
        // MR9: a save that moved a public claim did NOT go live — it queued.
        // Saying "saved" here is the one thing this screen must not do.
        onSuccess: (result) =>
          toast.success(
            result.queued !== null
              ? t('settings.profileQueued')
              : t('settings.profileSaved'),
          ),
        onError: (error) =>
          toast.error(apiErrorMessage(error, t('settings.profileSaveFailed'))),
      },
    );
  };

  const upload = (file: File, onSuccess: (queued?: boolean) => void) => {
    uploadLogo.mutate(file, {
      onSuccess: (response) => {
        // For a live store the file is STAGED, not applied: `logo_url` is
        // the logo still being served, and the uploader drops its local
        // preview so the tile never claims a swap that has not happened.
        const queued = response.data.change_request !== undefined;
        setLogoUrl(response.data.logo_url);
        toast.success(
          queued ? t('settings.logoQueued') : t('settings.logoUpdated'),
        );
        onSuccess(queued);
      },
      onError: (error) =>
        toast.error(apiErrorMessage(error, t('settings.logoUpdateFailed'))),
    });
  };

  return (
    <>
      {pending !== null && (
        <PendingChangeAlert
          change={pending}
          formatValue={formatChangeValue}
          className="mb-5"
        />
      )}

      <Card className="mb-5">
        <CardHeader>
          <CardTitle>{t('settings.identityTitle')}</CardTitle>
          <Badge variant={STATUS_VARIANT[profile.status]} appearance="light">
            {merchantStatusLabel(t, profile.status)}
          </Badge>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
          <span className="text-muted-foreground">
            {t('settings.storeName')}
          </span>
          <span>{profile.name}</span>
          <span className="text-muted-foreground">
            {t('settings.publicLink')}
          </span>
          <span className="flex flex-wrap items-center gap-2">
            <a
              href={storeUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="text-mono inline-flex items-center gap-1.5 text-primary hover:underline"
              dir="ltr"
            >
              {storeUrl.replace(/^https?:\/\//, '')}
              <ExternalLink className="size-3.5 shrink-0" />
            </a>
            <CopyButton value={storeUrl} label={t('settings.copyStoreLink')} />
          </span>
          <div className="col-span-2 pt-1.5 text-xs text-muted-foreground">
            {t('settings.renameNote')}
          </div>
        </CardContent>
      </Card>

      <Card className="mb-5">
        <CardHeader>
          <CardTitle>{t('settings.logoTitle')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <p className="text-sm text-muted-foreground">
            {t('settings.logoSubtitle')}
          </p>
          <LogoUploader
            currentUrl={logoUrl}
            upload={upload}
            uploading={uploadLogo.isPending}
          />
          {pendingLogoUrl !== null && (
            <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/40 p-3">
              <img
                src={pendingLogoUrl}
                alt={t('pending.logoProposedAlt')}
                className="size-12 shrink-0 rounded-md border border-border bg-background object-contain"
              />
              <p className="text-xs text-muted-foreground">
                {t('settings.logoPendingNote')}
              </p>
            </div>
          )}
        </CardContent>
      </Card>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>{t('settings.publicProfileTitle')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="store-name">{t('settings.storeNameLabel')}</Label>
              <Input
                id="store-name"
                value={name}
                maxLength={120}
                onChange={(event) => setName(event.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                {t('settings.storeNameHint')}
              </p>
            </div>

            <div className="flex flex-col gap-2.5">
              <Label htmlFor="store-name-dv">
                {t('settings.storeNameDvLabel')}
              </Label>
              <Input
                id="store-name-dv"
                dir="rtl"
                lang="dv"
                value={nameDv}
                maxLength={120}
                placeholder={t('settings.storeNameDvPlaceholder')}
                onChange={(event) => setNameDv(event.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                {t('settings.storeNameDvHint')}
              </p>
            </div>

            <div className="flex flex-col gap-2.5">
              <Label htmlFor="category">{t('settings.categoryLabel')}</Label>
              <Select value={category} onValueChange={setCategory}>
                <SelectTrigger id="category">
                  <SelectValue
                    placeholder={t('settings.categoryPlaceholder')}
                  />
                </SelectTrigger>
                <SelectContent>
                  {selectableSlugs.map((slug) => (
                    <SelectItem key={slug} value={slug}>
                      {categoryOptionLabel(slug)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p
                className={
                  showRetiredHint
                    ? 'text-xs text-warning'
                    : 'text-xs text-muted-foreground'
                }
              >
                {showRetiredHint
                  ? t('settings.categoryRetired')
                  : t('settings.categoryHint')}
              </p>
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="channel">{t('settings.channelLabel')}</Label>
              <Select
                value={channel}
                onValueChange={(value) => setChannel(value as MerchantChannel)}
              >
                <SelectTrigger id="channel">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {CHANNELS.map((option) => (
                    <SelectItem key={option} value={option}>
                      {merchantChannelLabel(t, option)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                {t('settings.channelHint')}
              </p>
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="contact-email">
                {t('settings.contactEmail')}
              </Label>
              <Input
                id="contact-email"
                type="email"
                value={contactEmail}
                maxLength={255}
                onChange={(event) => setContactEmail(event.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="contact-phone">
                {t('settings.contactPhone')}
              </Label>
              <Input
                id="contact-phone"
                dir="ltr"
                value={contactPhone}
                maxLength={32}
                onChange={(event) => setContactPhone(event.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="support-phone">
                {t('setup.supportPhoneLabel')}
              </Label>
              <label className="inline-flex items-center gap-2 text-sm text-muted-foreground">
                <input
                  type="checkbox"
                  className="size-4 rounded border-border"
                  checked={supportSameAsContact}
                  onChange={(event) =>
                    setSupportSameAsContact(event.target.checked)
                  }
                />
                {t('setup.supportSameAsContact')}
              </label>
              {!supportSameAsContact && (
                <Input
                  id="support-phone"
                  dir="ltr"
                  value={supportPhone}
                  maxLength={32}
                  onChange={(event) => setSupportPhone(event.target.value)}
                />
              )}
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="website-url">{t('setup.websiteLabel')}</Label>
              <Input
                id="website-url"
                dir="ltr"
                value={websiteUrl}
                maxLength={255}
                placeholder="teaplus.mv"
                onChange={(event) => setWebsiteUrl(event.target.value)}
              />
            </div>
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="eligibility-basis">{t('setup.termsLabel')}</Label>
            <Textarea
              id="eligibility-basis"
              value={eligibilityBasis}
              maxLength={2000}
              rows={4}
              placeholder={t('setup.termsPlaceholder')}
              onChange={(event) => setEligibilityBasis(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              {t('setup.termsGuidance')}
            </p>
          </div>
        </CardContent>
        <CardFooter className="flex-wrap items-center gap-3">
          {/* Said BEFORE the click, not only after it: which half of this
              form is a claim shoppers read (reviewed) and which half is the
              store's own contact detail (instant). */}
          {reviewed && (
            <p className="max-w-md text-xs text-muted-foreground">
              {t('settings.reviewedFieldsNote')}
            </p>
          )}
          <Button
            className="ms-auto"
            disabled={updateProfile.isPending}
            onClick={save}
          >
            {updateProfile.isPending && (
              <LoaderCircle className="animate-spin" />
            )}
            {t('settings.saveProfile')}
          </Button>
        </CardFooter>
      </Card>
    </>
  );
}

export default function ProfileSettingsPage() {
  const { t } = useTranslation();
  const profile = useProfile();
  // The setup state doubles as the read model for the curated category list
  // and the current logo URL (owner-only, like everything under settings).
  const setup = useSetupState();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('settings.profileTitle')}</ToolbarPageTitle>
          <ToolbarDescription>
            {t('settings.profileSubtitle')}
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {profile.error ? (
        <ErrorBlock error={profile.error} />
      ) : setup.error ? (
        <ErrorBlock error={setup.error} />
      ) : !profile.data || !setup.data ? (
        <LoadingBlock lines={6} />
      ) : (
        <ProfileForm profile={profile.data} setup={setup.data} />
      )}
    </div>
  );
}
