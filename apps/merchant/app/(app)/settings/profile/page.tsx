'use client';

import { useEffect, useState } from 'react';
import {
  type MerchantChannel,
  type MerchantProfile,
  type MerchantSetupState,
} from '@manfaa/api-client';
import { LoaderCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
  apiErrorMessage,
  useProfile,
  useSetupState,
  useUpdateProfile,
  useUploadSettingsLogo,
} from '@/lib/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

  const [category, setCategory] = useState(profile.category ?? '');
  const [channel, setChannel] = useState<MerchantChannel>(profile.channel);
  const [eligibilityBasis, setEligibilityBasis] = useState(
    profile.eligibility_basis ?? '',
  );
  const [contactEmail, setContactEmail] = useState(profile.contact_email ?? '');
  const [contactPhone, setContactPhone] = useState(profile.contact_phone ?? '');
  const [logoUrl, setLogoUrl] = useState(setup.values.logo_url);

  // Re-sync when a save comes back with the server's normalised values.
  useEffect(() => {
    setCategory(profile.category ?? '');
    setChannel(profile.channel);
    setEligibilityBasis(profile.eligibility_basis ?? '');
    setContactEmail(profile.contact_email ?? '');
    setContactPhone(profile.contact_phone ?? '');
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

  const save = () => {
    updateProfile.mutate(
      {
        category: category === '' ? null : category,
        channel,
        eligibility_basis:
          eligibilityBasis.trim() === '' ? null : eligibilityBasis,
        contact_email: contactEmail.trim() === '' ? null : contactEmail.trim(),
        contact_phone: contactPhone.trim() === '' ? null : contactPhone.trim(),
      },
      {
        onSuccess: () => toast.success(t('settings.profileSaved')),
        onError: (error) =>
          toast.error(apiErrorMessage(error, t('settings.profileSaveFailed'))),
      },
    );
  };

  const upload = (file: File, onSuccess: () => void) => {
    uploadLogo.mutate(file, {
      onSuccess: (response) => {
        setLogoUrl(response.data.logo_url);
        toast.success(t('settings.logoUpdated'));
        onSuccess();
      },
      onError: (error) =>
        toast.error(apiErrorMessage(error, t('settings.logoUpdateFailed'))),
    });
  };

  return (
    <>
      <Card className="mb-5">
        <CardHeader>
          <CardTitle>{t('settings.identityTitle')}</CardTitle>
          <Badge variant={STATUS_VARIANT[profile.status]} appearance="light">
            {t(`settings.status.${profile.status}`)}
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
          <span className="text-mono" dir="ltr">
            {profile.slug}
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
        </CardContent>
      </Card>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>{t('settings.publicProfileTitle')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
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
              <p className="text-xs text-muted-foreground">
                {t('settings.categoryHint')}
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
                      {t(`channel.${option}`)}
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
        <CardFooter className="justify-end">
          <Button disabled={updateProfile.isPending} onClick={save}>
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
