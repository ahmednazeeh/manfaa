'use client';

import { useState } from 'react';
import {
  getAdminAppReleases,
  updateAdminAppReleases,
  type AppReleaseFlags,
  type AppReleases,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';

/**
 * The mobile release gates, editable without a deploy. Per app and platform:
 * the oldest build the API keeps serving (the emergency lever that shuts a
 * bad build out), the newest build available (what the update prompt
 * advertises), and the store link an out-of-date install is sent to.
 *
 * The apps read these from the public config endpoint on every launch, so a
 * save is live within a minute. Until the first save each value shows the
 * server's env-backed default.
 */

const QUERY_KEY = ['admin', 'app-releases'] as const;

const APP_LABELS: Record<string, string> = {
  customer: 'Customer app',
  merchant: 'Merchant app',
};

const PLATFORM_LABELS: Record<string, string> = {
  ios: 'iOS',
  android: 'Android',
};

function appLabel(app: string): string {
  return APP_LABELS[app] ?? `${app.charAt(0).toUpperCase()}${app.slice(1)} app`;
}

function platformLabel(platform: string): string {
  return PLATFORM_LABELS[platform] ?? platform;
}

/** A whole build number (>= 1), or null when the field cannot be saved yet. */
function parseBuild(text: string): number | null {
  if (!/^\d+$/.test(text.trim())) {
    return null;
  }

  const value = Number(text.trim());

  return Number.isSafeInteger(value) && value >= 1 ? value : null;
}

function PlatformForm({
  app,
  platform,
  flags,
  releases,
}: {
  app: string;
  platform: string;
  flags: AppReleaseFlags;
  /** The full last-fetched set — a save must send every app and platform. */
  releases: AppReleases;
}) {
  const queryClient = useQueryClient();
  const [minimumBuild, setMinimumBuild] = useState(String(flags.minimum_build));
  const [latestBuild, setLatestBuild] = useState(String(flags.latest_build));
  const [storeUrl, setStoreUrl] = useState(flags.store_url ?? '');

  const minimum = parseBuild(minimumBuild);
  const latest = parseBuild(latestBuild);

  const dirty =
    minimumBuild !== String(flags.minimum_build) ||
    latestBuild !== String(flags.latest_build) ||
    storeUrl !== (flags.store_url ?? '');

  // Mirrors the server's 422s so an admin sees the problem before saving:
  // builds are whole numbers from 1, and a latest below the minimum would
  // prompt users toward a build the API refuses to serve.
  const problem =
    minimum === null || latest === null
      ? 'Builds are whole numbers, 1 or higher.'
      : latest < minimum
        ? 'Latest build cannot be below the minimum build.'
        : null;

  const save = useMutation({
    mutationFn: () => {
      const payload: AppReleases = {
        ...releases,
        [app]: {
          ...releases[app],
          [platform]: {
            minimum_build: minimum ?? flags.minimum_build,
            latest_build: latest ?? flags.latest_build,
            store_url: storeUrl.trim() === '' ? null : storeUrl.trim(),
          },
        },
      };

      return updateAdminAppReleases(payload);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY });
      toast.success(
        `${appLabel(app)} · ${platformLabel(platform)} release flags saved.`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const fieldId = (name: string) => `${app}-${platform}-${name}`;

  return (
    <div className="flex flex-col gap-4">
      <div className="text-sm font-medium">{platformLabel(platform)}</div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor={fieldId('minimum')}>Minimum supported build</Label>
          <Input
            id={fieldId('minimum')}
            type="number"
            min={1}
            step={1}
            inputMode="numeric"
            value={minimumBuild}
            onChange={(event) => setMinimumBuild(event.target.value)}
          />
          <p className="text-xs text-muted-foreground">
            Builds below this are refused and sent to the store. Raise it to
            shut a bad build out.
          </p>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor={fieldId('latest')}>Latest build</Label>
          <Input
            id={fieldId('latest')}
            type="number"
            min={1}
            step={1}
            inputMode="numeric"
            value={latestBuild}
            onChange={(event) => setLatestBuild(event.target.value)}
          />
          <p className="text-xs text-muted-foreground">
            The newest build in the store — what the in-app update prompt
            advertises.
          </p>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        <Label htmlFor={fieldId('store-url')}>Store URL</Label>
        <Input
          id={fieldId('store-url')}
          type="url"
          placeholder="https://…"
          value={storeUrl}
          onChange={(event) => setStoreUrl(event.target.value)}
        />
        <p className="text-xs text-muted-foreground">
          Where an out-of-date install is sent to update. Leave blank if the
          app is not listed yet.
        </p>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-destructive">{dirty ? problem : null}</p>
        <Button
          size="sm"
          disabled={!dirty || problem !== null || save.isPending}
          onClick={() => save.mutate()}
        >
          {save.isPending && <LoaderCircle className="animate-spin" />}
          Save
        </Button>
      </div>
    </div>
  );
}

export default function AppReleasesSettingsPage() {
  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: ({ signal }) => getAdminAppReleases({ signal }),
    select: (response) => response.data,
  });

  return (
    <div className="flex flex-col">
      <PageHeader
        title="App releases"
        description="The version gates the mobile apps read on launch. A save is live within a minute — no deploy. Raising the minimum build is the lever that stops an already-installed bad build talking to the API."
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : query.isPending ? (
        <div className="flex max-w-3xl flex-col gap-5">
          <Skeleton className="h-72 w-full" />
          <Skeleton className="h-72 w-full" />
        </div>
      ) : (
        <div className="flex max-w-3xl flex-col gap-5">
          {Object.entries(query.data).map(([app, platforms]) => (
            <Card key={app}>
              <CardHeader>
                <CardTitle>{appLabel(app)}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-6">
                {Object.entries(platforms).map(([platform, flags], index) => (
                  <div key={platform} className="flex flex-col gap-6">
                    {index > 0 ? <Separator /> : null}
                    <PlatformForm
                      // Remount when the server value changes so a saved
                      // form re-seeds from what the server now holds.
                      key={`${platform}-${flags.minimum_build}-${flags.latest_build}-${flags.store_url ?? ''}`}
                      app={app}
                      platform={platform}
                      flags={flags}
                      releases={query.data}
                    />
                  </div>
                ))}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
