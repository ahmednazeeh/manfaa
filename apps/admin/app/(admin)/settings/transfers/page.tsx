'use client';

import { useEffect, useState } from 'react';
import {
  getTransferSettings,
  updateTransferProfile,
  updateTransferSettings,
  updateWatchedAccount,
  type TransferProfile,
  type WatchedAccount,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Alert, AlertDescription, AlertIcon, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { PageHeader } from '@/components/admin/page-header';

/**
 * The bank transfer endpoint (PLAN-marketplace.md §21).
 *
 * The WireGuard peer does not exist yet, so nothing here is compiled in:
 * base URL, profile segment and debited account all change from this screen
 * the day the tunnel appears.
 *
 * The API key is deliberately absent. `x-api-key` is the whole of the
 * upstream's authentication, so it lives in the environment and this page is
 * told only whether one is set.
 */
export default function TransferSettingsPage() {
  const settings = useQuery({
    queryKey: ['admin', 'transfer-settings'],
    queryFn: ({ signal }) => getTransferSettings({ signal }),
  });

  const data = settings.data?.data;

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Transfer API"
        description="Where automatic bank transfers are sent. Each profile is a separate upstream session holding only its own accounts."
      />

      {settings.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : settings.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(settings.error)}</AlertDescription>
        </Alert>
      ) : data ? (
        <>
          {!data.api_key_configured ? (
            <Alert variant="warning" appearance="light">
              <AlertIcon>
                <KeyRound />
              </AlertIcon>
              <AlertTitle>No API key is configured</AlertTitle>
              <AlertDescription>
                Set <code>TETHERX_TRANSFER_API_KEY</code> in the server
                environment. It is never stored here — the key is the whole of
                the upstream&apos;s authentication, so a secret readable from an
                admin session would be a leaked bank.
              </AlertDescription>
            </Alert>
          ) : null}

          <AutoTransferCard
            enabled={data.auto_transfer_enabled}
            maxLaari={data.auto_max_laari}
            profileId={data.profile_id}
            profiles={data.profiles}
          />

          <AutoVerifyCard
            enabled={data.auto_verify_enabled}
            windowMinutes={data.verify_window_minutes}
            minScore={data.verify_min_score}
            accounts={data.watched_accounts}
            profiles={data.profiles}
          />

          <Card>
            <CardHeader>
              <CardTitle>Profiles</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col">
              {data.profiles.map((profile, index) => (
                <div key={profile.id}>
                  {index > 0 ? <Separator /> : null}
                  <ProfileRow profile={profile} />
                </div>
              ))}
            </CardContent>
          </Card>
        </>
      ) : null}
    </div>
  );
}

function AutoTransferCard({
  enabled,
  maxLaari,
  profileId,
  profiles,
}: {
  enabled: boolean;
  maxLaari: number;
  profileId: number | null;
  profiles: TransferProfile[];
}) {
  const queryClient = useQueryClient();
  const [max, setMax] = useState(() => (maxLaari / 100).toFixed(2));

  useEffect(() => setMax((maxLaari / 100).toFixed(2)), [maxLaari]);

  const save = useMutation({
    mutationFn: (body: Parameters<typeof updateTransferSettings>[0]) =>
      updateTransferSettings(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'transfer-settings'] });
      toast.success('Transfer settings saved.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const active = profiles.filter((profile) => profile.active);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Automatic transfers</CardTitle>
        <Badge variant={enabled ? 'success' : 'secondary'} appearance="light">
          {enabled ? 'On' : 'Off'}
        </Badge>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <div className="flex items-start justify-between gap-6">
          <div className="max-w-xl">
            <p className="text-sm font-medium">Send queued payouts automatically</p>
            <p className="text-sm text-muted-foreground">
              With this off, every payout waits in Pending payments for a
              person. Leave it off until the tunnel is up and a test transfer
              has been made — an automatic payer that silently fails every
              night is worse than a queue somebody can see.
            </p>
          </div>
          <Switch
            checked={enabled}
            disabled={save.isPending || active.length === 0}
            onCheckedChange={(next) =>
              save.mutate({ auto_transfer_enabled: next })
            }
          />
        </div>

        {active.length === 0 ? (
          <Alert variant="warning" appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              No profile is active yet, so automatic transfers cannot be
              switched on. Activate one below.
            </AlertDescription>
          </Alert>
        ) : null}

        <Separator />

        <div className="flex flex-col gap-2">
          <Label htmlFor="auto-profile">Profile used for automatic transfers</Label>
          <select
            id="auto-profile"
            className="h-9 w-full max-w-sm rounded-md border border-input bg-background px-3 text-sm"
            value={profileId ?? ''}
            onChange={(event) =>
              save.mutate({
                profile_id: event.target.value === ''
                  ? null
                  : Number(event.target.value),
              })
            }
          >
            <option value="">Use the default profile</option>
            {profiles.map((profile) => (
              <option key={profile.id} value={profile.id}>
                {profile.name} ({profile.segment})
                {profile.active ? '' : ' — inactive'}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="auto-max">Ceiling per automatic transfer</Label>
          <div className="flex items-center gap-2">
            <div className="relative">
              <span className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-sm text-muted-foreground">
                MVR
              </span>
              <Input
                id="auto-max"
                className="w-44 ps-12"
                inputMode="decimal"
                value={max}
                onChange={(event) => setMax(event.target.value)}
              />
            </div>
            <Button
              variant="outline"
              disabled={save.isPending}
              onClick={() => {
                const laari = Math.round(Number(max) * 100);

                if (!Number.isFinite(laari) || laari < 0) {
                  toast.error('Enter an amount, e.g. 5000.00.');
                  return;
                }

                save.mutate({ auto_max_laari: laari });
              }}
            >
              Save
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            Anything above this waits for a person, whatever the switch says.
          </p>
        </div>
      </CardContent>
    </Card>
  );
}

function ProfileRow({ profile }: { profile: TransferProfile }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    base_url: profile.base_url,
    segment: profile.segment,
    from_account: profile.from_account ?? '',
    bank: profile.bank ?? '',
  });

  useEffect(() => {
    setForm({
      base_url: profile.base_url,
      segment: profile.segment,
      from_account: profile.from_account ?? '',
      bank: profile.bank ?? '',
    });
  }, [profile.base_url, profile.segment, profile.from_account, profile.bank]);

  const save = useMutation({
    mutationFn: (body: Parameters<typeof updateTransferProfile>[1]) =>
      updateTransferProfile(profile.id, body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'transfer-settings'] });
      toast.success(`${profile.name} saved.`);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const dirty =
    form.base_url !== profile.base_url ||
    form.segment !== profile.segment ||
    form.from_account !== (profile.from_account ?? '') ||
    form.bank !== (profile.bank ?? '');

  return (
    <div className="flex flex-col gap-4 py-5">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">{profile.name}</span>
        {profile.is_default ? (
          <Badge variant="info" appearance="light" size="sm">
            Default
          </Badge>
        ) : null}
        {profile.dual_control ? (
          <Badge variant="warning" appearance="light" size="sm">
            Dual control
          </Badge>
        ) : null}
        <Badge
          variant={profile.active ? 'success' : 'secondary'}
          appearance="light"
          size="sm"
        >
          {profile.active ? 'Active' : 'Inactive'}
        </Badge>
        <code className="ms-auto text-xs text-muted-foreground">
          {profile.endpoint}
        </code>
      </div>

      {profile.dual_control ? (
        <p className="text-xs text-muted-foreground">
          This upstream can answer with <strong>pending approval</strong>: the
          transfer is accepted and parked for a second approver, not failed. It
          is never re-sent, and the approval id it returns is a queue record —
          not a bank reference.
        </p>
      ) : null}

      <div className="grid gap-3 sm:grid-cols-4">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor={`base-${profile.id}`}>Base URL</Label>
          <Input
            id={`base-${profile.id}`}
            value={form.base_url}
            placeholder="http://10.99.0.1:3005"
            onChange={(event) =>
              setForm({ ...form, base_url: event.target.value })
            }
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor={`segment-${profile.id}`}>Profile segment</Label>
          <Input
            id={`segment-${profile.id}`}
            value={form.segment}
            placeholder="/faisanet"
            onChange={(event) =>
              setForm({ ...form, segment: event.target.value })
            }
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor={`from-${profile.id}`}>Debited account</Label>
          <Input
            id={`from-${profile.id}`}
            value={form.from_account}
            placeholder="90501400021681000"
            onChange={(event) =>
              setForm({ ...form, from_account: event.target.value })
            }
          />
          <p className="text-xs text-muted-foreground">
            Must be one of this profile&apos;s own accounts. Ignored on
            /bml/transfer, which is a different upstream.
          </p>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor={`bank-${profile.id}`}>Bank</Label>
          <select
            id={`bank-${profile.id}`}
            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            value={form.bank}
            onChange={(event) => setForm({ ...form, bank: event.target.value })}
          >
            <option value="">No preference</option>
            <option value="mib">MIB</option>
            <option value="bml">BML</option>
          </select>
          <p className="text-xs text-muted-foreground">
            A payout to a payee at this bank leaves from here rather than
            crossing banks. Leave unset and every payout uses the batch
            profile.
          </p>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button
          size="sm"
          disabled={!dirty || save.isPending}
          onClick={() =>
            save.mutate({ ...form, bank: form.bank === '' ? null : (form.bank as 'mib' | 'bml') })
          }
        >
          {save.isPending ? 'Saving…' : 'Save'}
        </Button>
        <Button
          size="sm"
          variant="outline"
          disabled={save.isPending}
          onClick={() => save.mutate({ active: !profile.active })}
        >
          {profile.active ? 'Deactivate' : 'Activate'}
        </Button>
        {!profile.is_default ? (
          <Button
            size="sm"
            variant="ghost"
            disabled={save.isPending}
            onClick={() => save.mutate({ is_default: true })}
          >
            Make default
          </Button>
        ) : null}
      </div>
    </div>
  );
}

/**
 * Auto-matching an incoming bank credit to an order.
 *
 * Behind its OWN flag, separate from automatic transfers: the two use the
 * same upstream but are opposite directions of money, and the API access is
 * not live yet (owner requirement 2026-08-19). Everything here ships dark
 * until an operator turns it on.
 */
function AutoVerifyCard({
  enabled,
  windowMinutes,
  minScore,
  accounts,
  profiles,
}: {
  enabled: boolean;
  windowMinutes: number;
  minScore: number;
  accounts: WatchedAccount[];
  profiles: TransferProfile[];
}) {
  const queryClient = useQueryClient();
  const [minutes, setMinutes] = useState(String(windowMinutes));
  const [score, setScore] = useState(String(minScore));

  useEffect(() => setMinutes(String(windowMinutes)), [windowMinutes]);
  useEffect(() => setScore(String(minScore)), [minScore]);

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['admin', 'transfer-settings'] });

  const save = useMutation({
    mutationFn: (body: Parameters<typeof updateTransferSettings>[0]) =>
      updateTransferSettings(body),
    onSuccess: () => {
      invalidate();
      toast.success('Auto-verification saved.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const mapAccount = useMutation({
    mutationFn: ({ id, profileId }: { id: number; profileId: number | null }) =>
      updateWatchedAccount(id, { verify_profile_id: profileId }),
    onSuccess: () => {
      invalidate();
      toast.success('Watched account saved.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const watched = accounts.filter((account) => account.verify_profile_id !== null);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Automatic payment verification</CardTitle>
        <Badge variant={enabled ? 'success' : 'secondary'} appearance="light">
          {enabled ? 'On' : 'Off'}
        </Badge>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <div className="flex items-start justify-between gap-6">
          <div className="max-w-xl">
            <p className="text-sm font-medium">
              Match customer payments against the bank automatically
            </p>
            <p className="text-sm text-muted-foreground">
              After a customer uploads a receipt we read the bank&apos;s own
              history for a credit of the exact amount from a name that
              matches theirs. A match verifies the order and tells the shops.
              With this off, every payment waits in Marketplace payments for a
              person — which is where it stays anyway if nothing matches.
            </p>
          </div>
          <Switch
            checked={enabled}
            disabled={save.isPending || watched.length === 0}
            onCheckedChange={(next) => save.mutate({ auto_verify_enabled: next })}
          />
        </div>

        {watched.length === 0 ? (
          <Alert variant="warning" appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>
              No account is being watched yet, so there is nothing to match
              against. Point at least one account at a profile below.
            </AlertDescription>
          </Alert>
        ) : null}

        <Separator />

        <div className="flex flex-col gap-3">
          <div>
            <p className="text-sm font-medium">Accounts we watch</p>
            <p className="text-sm text-muted-foreground">
              Customers choose their bank at checkout, so each of our accounts
              needs the profile that reads <em>its</em> history. An account
              with no profile is verified by hand — nothing falls back to
              another bank, because reading the wrong ledger could only
              produce a match that means nothing.
            </p>
          </div>

          {accounts.map((account) => (
            <div
              key={account.id}
              className="flex flex-wrap items-center gap-3 rounded-md border border-border px-3 py-2"
            >
              <div className="min-w-48">
                <p className="text-sm font-medium uppercase">
                  {account.bank_name}
                  {account.is_primary ? (
                    <Badge
                      variant="info"
                      appearance="light"
                      size="sm"
                      className="ms-2"
                    >
                      Primary
                    </Badge>
                  ) : null}
                </p>
                <code className="text-xs text-muted-foreground">
                  {account.account_no}
                </code>
              </div>
              <select
                aria-label={`Profile that reads ${account.bank_name} ${account.account_no}`}
                className="ms-auto h-9 w-full max-w-xs rounded-md border border-input bg-background px-3 text-sm"
                value={account.verify_profile_id ?? ''}
                disabled={mapAccount.isPending}
                onChange={(event) =>
                  mapAccount.mutate({
                    id: account.id,
                    profileId:
                      event.target.value === ''
                        ? null
                        : Number(event.target.value),
                  })
                }
              >
                <option value="">Not watched — verified by hand</option>
                {profiles.map((profile) => (
                  <option key={profile.id} value={profile.id}>
                    {profile.name} ({profile.segment})
                    {profile.active ? '' : ' — inactive'}
                  </option>
                ))}
              </select>
            </div>
          ))}

          {accounts.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No platform bank accounts exist yet.
            </p>
          ) : null}
        </div>

        <Separator />

        <div className="grid gap-3 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="verify-window">Watch for</Label>
            <div className="flex items-center gap-2">
              <Input
                id="verify-window"
                inputMode="numeric"
                className="max-w-24"
                value={minutes}
                onChange={(event) => setMinutes(event.target.value)}
                onBlur={() => {
                  const next = Number(minutes);
                  if (Number.isInteger(next) && next >= 1 && next <= 180 && next !== windowMinutes) {
                    save.mutate({ verify_window_minutes: next });
                  } else {
                    setMinutes(String(windowMinutes));
                  }
                }}
              />
              <span className="text-sm text-muted-foreground">
                minutes after the receipt
              </span>
            </div>
            <p className="text-xs text-muted-foreground">
              Bounded on purpose. A payment that lands later than this deserves
              a person looking at it anyway.
            </p>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="verify-score">Minimum name score</Label>
            <div className="flex items-center gap-2">
              <Input
                id="verify-score"
                inputMode="numeric"
                className="max-w-24"
                value={score}
                onChange={(event) => setScore(event.target.value)}
                onBlur={() => {
                  const next = Number(score);
                  if (Number.isInteger(next) && next >= 30 && next <= 100 && next !== minScore) {
                    save.mutate({ verify_min_score: next });
                  } else {
                    setScore(String(minScore));
                  }
                }}
              />
              <span className="text-sm text-muted-foreground">out of 100</span>
            </div>
            <p className="text-xs text-muted-foreground">
              60 matches &ldquo;Ahmed Nazeeh&rdquo; to &ldquo;AHMD NAZEEH&rdquo;
              and &ldquo;Ahmed Nazeeh Adam&rdquo; to &ldquo;AHMD N ADAM&rdquo;,
              while a different person scores nothing at all. 100 accepts only
              an exact name.
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
