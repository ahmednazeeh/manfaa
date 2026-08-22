'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import type { ConnectConsentQuery } from '@manfaa/api-client';
import {
  ArrowRight,
  Check,
  ExternalLink,
  LoaderCircle,
  ShieldCheck,
  TriangleAlert,
} from 'lucide-react';
import {
  apiErrorMessage,
  useApproveConnect,
  useConnectConsent,
  useDenyConnect,
} from '@/lib/queries';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * "IsleBooks would like to … Authorise / Deny".
 *
 * The platform sent the shopkeeper here with everything in the URL. This
 * screen asks the server what those parameters mean, shows the sentences,
 * and — if the answer is yes — sends the browser back to the platform with
 * a code good for one minute and one use.
 *
 * What comes out of that code does not expire. So the screen says what is
 * being granted in plain words, warns when a permission moves money, and
 * says out loud when authorising will REPLACE an existing connection.
 */
export default function ConnectConsentPage() {
  const params = useConnectParams();
  const consent = useConnectConsent(params ? { ...params.consent } : null);
  const approve = useApproveConnect();
  const deny = useDenyConnect();
  const [leaving, setLeaving] = useState(false);

  // Sending the browser on is the point of the screen; once it starts, the
  // buttons stay down so a second click cannot spend a second code.
  const busy = approve.isPending || deny.isPending || leaving;

  // Authorise cannot succeed — the store is at its credential ceiling. Deny
  // still can, and should: the platform deserves an answer either way.
  const blocked = consent.data?.blocked_reason ?? null;

  const answer = (redirectTo: string) => {
    setLeaving(true);
    window.location.assign(redirectTo);
  };

  // Undefined until the mount effect has read the URL — one frame, and the
  // skeleton is what the loaded state uses anyway.
  if (params === undefined) {
    return (
      <div className="mx-auto flex w-full max-w-xl flex-col gap-3 py-6">
        <Skeleton className="h-7 w-2/3" />
        <Skeleton className="h-5 w-full" />
        <Skeleton className="h-20 w-full" />
      </div>
    );
  }

  if (params === null) {
    return (
      <Refusal
        title="Something is missing from this link"
        body="Open the connection from the application you want to connect — the link it sends you carries what Manfaa needs to identify it."
      />
    );
  }

  if (consent.isError) {
    return (
      <Refusal
        title="This application cannot be connected"
        body={apiErrorMessage(
          consent.error,
          'Manfaa does not recognise this application, or it is asking for something it is not allowed to ask for. Nothing has been granted.',
        )}
      />
    );
  }

  return (
    <div className="mx-auto flex w-full max-w-xl flex-col gap-5 py-6">
      <Card>
        <CardContent className="flex flex-col gap-6 p-6">
          {consent.isPending || !consent.data ? (
            <div className="flex flex-col gap-3">
              <Skeleton className="h-7 w-2/3" />
              <Skeleton className="h-5 w-full" />
              <Skeleton className="h-20 w-full" />
            </div>
          ) : (
            <>
              <div className="flex flex-col gap-1.5">
                <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Connect an application
                </span>
                <h1 className="text-xl font-semibold">
                  {consent.data.application.name} would like access to{' '}
                  {consent.data.store.name}
                </h1>
                {consent.data.callback_host ? (
                  // A public client (a plugin on the merchant's own site)
                  // sends its own callback, so the store being connected
                  // is named here — the one fact that tells a real
                  // connection from a page that merely looks like one.
                  <p className="text-sm">
                    This will connect{' '}
                    <span className="font-semibold">{consent.data.callback_host}</span>
                    . If that is not your website, press Deny.
                  </p>
                ) : null}
                {consent.data.application.description ? (
                  <p className="text-sm text-muted-foreground">
                    {consent.data.application.description}
                  </p>
                ) : null}
                {consent.data.application.website ? (
                  <a
                    href={consent.data.application.website}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="inline-flex w-fit items-center gap-1 text-xs text-primary hover:underline"
                  >
                    {consent.data.application.website}
                    <ExternalLink className="size-3" />
                  </a>
                ) : null}
              </div>

              <div className="flex flex-col gap-2.5">
                <span className="text-sm font-medium">It will be able to:</span>
                {consent.data.permissions.map((permission) => (
                  <div
                    key={permission.ability}
                    className="flex items-start gap-2.5 rounded-md border border-border p-3"
                  >
                    <Check className="mt-0.5 size-4 shrink-0 text-success" />
                    <div className="flex min-w-0 flex-col gap-0.5">
                      <span className="text-sm">{permission.line}</span>
                      {permission.caution ? (
                        <span className="text-xs text-warning">
                          {permission.caution}
                        </span>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>

              {blocked ? (
                <Alert variant="destructive" appearance="light">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertContent>
                    <AlertTitle>
                      This store cannot take another connection
                    </AlertTitle>
                    <AlertDescription>{blocked}</AlertDescription>
                  </AlertContent>
                </Alert>
              ) : null}

              {consent.data.already_connected ? (
                <Alert variant="warning" appearance="light">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertContent>
                    <AlertTitle>
                      {consent.data.callback_host
                        ? `${consent.data.callback_host} is already connected`
                        : 'This application is already connected'}
                    </AlertTitle>
                    <AlertDescription>
                      Authorising again replaces the existing connection. The
                      key it holds now will stop working immediately.
                    </AlertDescription>
                  </AlertContent>
                </Alert>
              ) : null}

              <Alert appearance="light">
                <AlertIcon>
                  <ShieldCheck />
                </AlertIcon>
                <AlertContent>
                  <AlertDescription>
                    Access lasts until you end it. You can disconnect this
                    application at any time from{' '}
                    <Link
                      href="/settings/api-access"
                      className="font-medium text-primary hover:underline"
                    >
                      Settings → API access
                    </Link>
                    . Manfaa never shares your password, and this application
                    can never do more than the list above.
                  </AlertDescription>
                </AlertContent>
              </Alert>

              {approve.isError || deny.isError ? (
                <Alert variant="destructive" appearance="light">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertDescription>
                    {apiErrorMessage(
                      approve.error ?? deny.error,
                      'That did not go through. Nothing has been granted — try again.',
                    )}
                  </AlertDescription>
                </Alert>
              ) : null}

              <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  variant="outline"
                  disabled={busy}
                  onClick={() =>
                    deny.mutate(params.deny, {
                      onSuccess: (response) =>
                        answer(response.data.redirect_to),
                    })
                  }
                >
                  Deny
                </Button>
                <Button
                  disabled={busy || blocked !== null}
                  onClick={() =>
                    approve.mutate(params.approve, {
                      onSuccess: (response) =>
                        answer(response.data.redirect_to),
                    })
                  }
                >
                  {busy ? (
                    <LoaderCircle className="animate-spin" />
                  ) : (
                    <ArrowRight />
                  )}
                  Authorise
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

/**
 * Everything the platform put in the URL, read once on mount.
 *
 * Read from `window.location` rather than `useSearchParams` so this page
 * renders without a client-side bailout — and read ONCE, because the values
 * are answered as they arrived, not as some later navigation left them.
 */
function useConnectParams() {
  const [search, setSearch] = useState<string | null>(null);

  useEffect(() => setSearch(window.location.search), []);

  return useMemo(() => {
    if (search === null) {
      return undefined;
    }

    const query = new URLSearchParams(search);
    const clientId = query.get('client_id');
    const redirectUri = query.get('redirect_uri');
    const scope = query.get('scope');
    const challenge = query.get('code_challenge');
    const method = query.get('code_challenge_method');
    const state = query.get('state');

    // PKCE is not optional here. A platform that omits the challenge has
    // not implemented the flow, and a code without one is a code anybody
    // who sees the redirect can spend.
    if (
      !clientId ||
      !redirectUri ||
      !scope ||
      !challenge ||
      method !== 'S256'
    ) {
      return null;
    }

    const consent: ConnectConsentQuery = {
      client_id: clientId,
      redirect_uri: redirectUri,
      scope,
    };

    return {
      consent,
      approve: {
        ...consent,
        state,
        code_challenge: challenge,
        code_challenge_method: 'S256' as const,
      },
      deny: { client_id: clientId, redirect_uri: redirectUri, state },
    };
  }, [search]);
}

function Refusal({ title, body }: { title: string; body: string }) {
  return (
    <div className="mx-auto flex w-full max-w-xl flex-col gap-4 py-6">
      <Alert variant="destructive" appearance="light">
        <AlertIcon>
          <TriangleAlert />
        </AlertIcon>
        <AlertContent>
          <AlertTitle>{title}</AlertTitle>
          <AlertDescription>{body}</AlertDescription>
        </AlertContent>
      </Alert>
      <Button variant="outline" asChild className="w-fit">
        <Link href="/dashboard">Back to the dashboard</Link>
      </Button>
    </div>
  );
}
