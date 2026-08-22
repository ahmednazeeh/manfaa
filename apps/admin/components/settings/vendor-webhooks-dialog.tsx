'use client';

import { useState } from 'react';
import {
  createVendorWebhookEndpoint,
  deleteVendorWebhookEndpoint,
  listVendorWebhookEndpoints,
  testVendorWebhookEndpoint,
  WEBHOOK_EVENTS,
  type PlatformClient,
  type CreateVendorWebhookEndpointResponse,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, Plus, Send, Trash2, TriangleAlert, Webhook } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The webhook endpoints a PLATFORM owns (owner, 2026-08-22 — until now
 * these existed only as an API nobody had a screen for). One endpoint
 * receives every connected merchant's events for that platform; the
 * signing secret is shown exactly once, here.
 */
const EVENT_LABEL: Record<string, string> = {
  'merchant.rate_changed': 'A merchant’s rate changed',
  'merchant.suspended': 'A merchant was suspended',
  'merchant.reinstated': 'A merchant was reinstated',
  'transaction.reversed': 'A sale was reversed',
};

export function VendorWebhooksDialog({ client }: { client: PlatformClient }) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <Webhook />
          Webhooks
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Webhooks — {client.display_name || client.name}</DialogTitle>
          <DialogDescription>
            Where Manfaa delivers this platform&apos;s events. Every event
            carries <code>merchant_id</code>; the platform routes it on its
            side. A merchant is covered while it holds a live credential for
            this platform.
          </DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          {open ? <Endpoints client={client} /> : null}
        </DialogBody>
      </DialogContent>
    </Dialog>
  );
}

function Endpoints({ client }: { client: PlatformClient }) {
  const queryClient = useQueryClient();
  const key = ['admin', 'platform-clients', client.id, 'webhooks'];
  const query = useQuery({
    queryKey: key,
    queryFn: ({ signal }) => listVendorWebhookEndpoints(client.id, { signal }),
  });
  const [issued, setIssued] = useState<CreateVendorWebhookEndpointResponse | null>(null);
  const [adding, setAdding] = useState(false);

  const remove = useMutation({
    mutationFn: (id: number) => deleteVendorWebhookEndpoint(client.id, id),
    onSuccess: () => {
      toast.success('Endpoint removed.');
      queryClient.invalidateQueries({ queryKey: key });
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const test = useMutation({
    mutationFn: (id: number) => testVendorWebhookEndpoint(client.id, id),
    onSuccess: () => {
      toast.success('Test delivery queued — the platform should answer within seconds.');
      setTimeout(() => queryClient.invalidateQueries({ queryKey: key }), 4000);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-2">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    );
  }

  if (query.isError) {
    return (
      <Alert variant="destructive" appearance="light">
        <AlertIcon><TriangleAlert /></AlertIcon>
        <AlertContent><AlertDescription>{apiErrorMessage(query.error)}</AlertDescription></AlertContent>
      </Alert>
    );
  }

  const rows = query.data ?? [];

  return (
    <>
      {issued ? <SecretHandover issued={issued} onDone={() => setIssued(null)} /> : null}

      {rows.length === 0 && !adding ? (
        <p className="text-sm text-muted-foreground">
          No endpoint yet. Until one exists this platform hears nothing — not
          rate changes, not reversals.
        </p>
      ) : null}

      {rows.map((endpoint) => (
        <div key={endpoint.id} className="flex flex-col gap-2 rounded-md border border-border p-3">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 flex-1">
              <code className="block truncate font-mono text-xs">{endpoint.url}</code>
              <div className="mt-1.5 flex flex-wrap gap-1">
                {endpoint.events.map((event) => (
                  <Badge key={event} variant="secondary" appearance="light" size="sm">
                    {EVENT_LABEL[event] ?? event}
                  </Badge>
                ))}
                {!endpoint.active ? (
                  <Badge variant="destructive" appearance="light" size="sm">Off</Badge>
                ) : null}
              </div>
              <p className="mt-1.5 text-xs text-muted-foreground">
                {endpoint.last_delivery
                  ? `Last delivery: ${endpoint.last_delivery.event} · ${endpoint.last_delivery.status}${endpoint.last_delivery.response_status ? ` (${endpoint.last_delivery.response_status})` : ''}${endpoint.last_delivery.attempted_at ? ` · ${new Date(endpoint.last_delivery.attempted_at).toLocaleString()}` : ''}`
                  : 'Nothing delivered yet.'}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              <Button variant="outline" size="sm" disabled={test.isPending} onClick={() => test.mutate(endpoint.id)}>
                <Send />
                Send test
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="text-destructive"
                disabled={remove.isPending}
                onClick={() => {
                  if (window.confirm('Remove this endpoint? The platform stops receiving events immediately and its secret cannot be recovered.')) {
                    remove.mutate(endpoint.id);
                  }
                }}
              >
                <Trash2 />
              </Button>
            </div>
          </div>
        </div>
      ))}

      {adding ? (
        <AddEndpoint
          client={client}
          onCancel={() => setAdding(false)}
          onCreated={(response) => {
            setAdding(false);
            setIssued(response);
            queryClient.invalidateQueries({ queryKey: key });
          }}
        />
      ) : (
        <div>
          <Button size="sm" onClick={() => setAdding(true)}>
            <Plus />
            Add endpoint
          </Button>
        </div>
      )}
    </>
  );
}

function AddEndpoint({
  client,
  onCancel,
  onCreated,
}: {
  client: PlatformClient;
  onCancel: () => void;
  onCreated: (response: CreateVendorWebhookEndpointResponse) => void;
}) {
  const [url, setUrl] = useState('');
  const [events, setEvents] = useState<string[]>([...WEBHOOK_EVENTS]);

  const create = useMutation({
    mutationFn: () => createVendorWebhookEndpoint(client.id, { url: url.trim(), events }),
    onSuccess: onCreated,
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <form
      className="flex flex-col gap-3 rounded-md border border-dashed border-border p-3"
      onSubmit={(event) => {
        event.preventDefault();
        create.mutate();
      }}
    >
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="vendor-webhook-url">Endpoint URL</Label>
        <Input
          id="vendor-webhook-url"
          className="font-mono text-xs"
          placeholder="https://api.islebooks.mv/manfaa/webhook"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          required
        />
        <p className="text-xs text-muted-foreground">
          https only, on a public host — the queue worker POSTs signed JSON here.
        </p>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label>Events</Label>
        {WEBHOOK_EVENTS.map((event) => (
          <label key={event} className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={events.includes(event)}
              onCheckedChange={(checked) =>
                setEvents((current) =>
                  checked ? [...current, event] : current.filter((e) => e !== event),
                )
              }
            />
            {EVENT_LABEL[event] ?? event}
          </label>
        ))}
      </div>
      <DialogFooter className="px-0 pb-0">
        <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>
        <Button type="submit" disabled={create.isPending || events.length === 0 || url.trim() === ''}>
          Create endpoint
        </Button>
      </DialogFooter>
    </form>
  );
}

function SecretHandover({
  issued,
  onDone,
}: {
  issued: CreateVendorWebhookEndpointResponse;
  onDone: () => void;
}) {
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  return (
    <Alert variant="warning" appearance="light">
      <AlertIcon><TriangleAlert /></AlertIcon>
      <AlertContent className="flex flex-col gap-2">
        <AlertTitle>Signing secret — shown once</AlertTitle>
        <AlertDescription>
          Send this to the platform over a secure channel. Every delivery to{' '}
          <code className="text-xs">{issued.endpoint.url}</code> carries an
          X-Manfaa-Signature computed with it; it cannot be shown again.
        </AlertDescription>
        <div className="flex items-center gap-2">
          <code className="flex-1 overflow-x-auto rounded-md border border-border bg-background px-3 py-2 font-mono text-xs">
            {issued.secret}
          </code>
          <Button type="button" variant="outline" size="icon" aria-label="Copy secret" onClick={() => copyToClipboard(issued.secret)}>
            {isCopied ? <Check className="text-success" /> : <Copy />}
          </Button>
        </div>
        <div>
          <Button type="button" size="sm" variant="outline" onClick={onDone}>I have copied it</Button>
        </div>
      </AlertContent>
    </Alert>
  );
}
