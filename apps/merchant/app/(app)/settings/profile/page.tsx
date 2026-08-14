'use client';

import { useEffect, useState } from 'react';
import { type MerchantProfile } from '@manfaa/api-client';
import { LoaderCircle } from 'lucide-react';
import { apiErrorMessage, useProfile, useUpdateProfile } from '@/lib/queries';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'sonner';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';

const STATUS_VARIANT = {
  active: 'success',
  suspended: 'destructive',
  closed: 'secondary',
} as const;

function ProfileForm({ profile }: { profile: MerchantProfile }) {
  const updateProfile = useUpdateProfile();

  const [category, setCategory] = useState(profile.category ?? '');
  const [isOnline, setIsOnline] = useState(profile.is_online);
  const [eligibilityBasis, setEligibilityBasis] = useState(
    profile.eligibility_basis ?? '',
  );
  const [contactEmail, setContactEmail] = useState(profile.contact_email ?? '');
  const [contactPhone, setContactPhone] = useState(profile.contact_phone ?? '');

  // Re-sync when a save comes back with the server's normalised values.
  useEffect(() => {
    setCategory(profile.category ?? '');
    setIsOnline(profile.is_online);
    setEligibilityBasis(profile.eligibility_basis ?? '');
    setContactEmail(profile.contact_email ?? '');
    setContactPhone(profile.contact_phone ?? '');
  }, [profile]);

  const save = () => {
    updateProfile.mutate(
      {
        category: category.trim() === '' ? null : category.trim(),
        is_online: isOnline,
        eligibility_basis:
          eligibilityBasis.trim() === '' ? null : eligibilityBasis,
        contact_email: contactEmail.trim() === '' ? null : contactEmail.trim(),
        contact_phone: contactPhone.trim() === '' ? null : contactPhone.trim(),
      },
      {
        onSuccess: () => toast.success('Profile saved'),
        onError: (error) =>
          toast.error(apiErrorMessage(error, 'Could not save the profile.')),
      },
    );
  };

  return (
    <>
      <Card className="mb-5">
        <CardHeader>
          <CardTitle>Business identity</CardTitle>
          <Badge variant={STATUS_VARIANT[profile.status]} appearance="light">
            {profile.status}
          </Badge>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
          <span className="text-muted-foreground">Store name</span>
          <span>{profile.name}</span>
          <span className="text-muted-foreground">Public link</span>
          <span className="text-mono">{profile.slug}</span>
          <div className="col-span-2 pt-1.5 text-xs text-muted-foreground">
            Renaming the business is an identity change — contact Manfaa to
            update it.
          </div>
        </CardContent>
      </Card>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>Public profile</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-5">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="category">Category</Label>
              <Input
                id="category"
                value={category}
                maxLength={100}
                placeholder="e.g. Café, Grocery, Electronics"
                onChange={(event) => setCategory(event.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Shown in discovery so customers can find stores like yours.
              </p>
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="is-online">Online store</Label>
              <div className="flex items-center gap-2.5 h-8.5">
                <Switch
                  id="is-online"
                  checked={isOnline}
                  onCheckedChange={setIsOnline}
                />
                <span className="text-sm text-secondary-foreground">
                  {isOnline ? 'Listed under online stores' : 'Physical store only'}
                </span>
              </div>
              <p className="text-xs text-muted-foreground">
                Controls where you appear in the customer app&apos;s discovery
                sections.
              </p>
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="contact-email">Contact email</Label>
              <Input
                id="contact-email"
                type="email"
                value={contactEmail}
                maxLength={255}
                onChange={(event) => setContactEmail(event.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="contact-phone">Contact phone</Label>
              <Input
                id="contact-phone"
                value={contactPhone}
                maxLength={32}
                onChange={(event) => setContactPhone(event.target.value)}
              />
            </div>
          </div>

          <div className="flex flex-col gap-2.5">
            <Label htmlFor="eligibility-basis">What earns cashback</Label>
            <Textarea
              id="eligibility-basis"
              value={eligibilityBasis}
              maxLength={2000}
              rows={4}
              placeholder="e.g. Cashback on the food total, excluding service charge and delivery."
              onChange={(event) => setEligibilityBasis(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Displayed to customers exactly as written, so they know what
              part of the bill earns cashback. It mirrors your agreement and
              is never used in computation.
            </p>
          </div>
        </CardContent>
        <CardFooter className="justify-end">
          <Button disabled={updateProfile.isPending} onClick={save}>
            {updateProfile.isPending && (
              <LoaderCircle className="animate-spin" />
            )}
            Save profile
          </Button>
        </CardFooter>
      </Card>
    </>
  );
}

export default function ProfileSettingsPage() {
  const profile = useProfile();

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Profile</ToolbarPageTitle>
          <ToolbarDescription>
            What customers see about your store
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      {profile.error ? (
        <ErrorBlock error={profile.error} />
      ) : !profile.data ? (
        <LoadingBlock lines={6} />
      ) : (
        <ProfileForm profile={profile.data} />
      )}
    </div>
  );
}
