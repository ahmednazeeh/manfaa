'use client';

import { useState } from 'react';
import {
  WEBHOOK_EVENTS,
  type CreateMerchantWebhookEndpointResponse,
  type MerchantWebhookEndpoint,
  type WebhookEvent,
} from '@manfaa/api-client';
import { format } from 'date-fns';
import { Copy, Plug, Plus, Send, Trash2, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { INTEGRATION_GUIDE_URL } from '@/lib/integration';
import {
  apiErrorMessage,
  useCreateWebhookEndpoint,
  useDeleteWebhookEndpoint,
  useTestWebhookEndpoint,
  useWebhookEndpoints,
} from '@/lib/queries';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
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
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';

/**
 * Settings › API access › Webhooks (owner, 2026-08-22).
 *
 * A store's OWN webhook endpoints — for a custom shop, an ERP, anything
 * that wants to be told when the rate changes or a sale is reversed and is
 * not a POS platform we onboarded by hand. Same signing scheme as the
 * vendor webhooks in the guide; the only difference is that these hear one
 * store's events and nobody else's.
 *
 * Mirrors the credentials card above it: the secret is shown exactly once
 * behind an acknowledge checkbox, rows made by a plugin over the API are
 * labelled as such, and removal asks first.
 */
const ACTIVE_CAP = 5;

function formatMoment(value: string | null): string | null {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : format(date, 'd MMM yyyy, HH:mm');
}

function DeliveryBadge({ endpoint }: { endpoint: MerchantWebhookEndpoint }) {
  const { t } = useTranslation();
  const last = endpoint.last_delivery;

  if (!endpoint.active) {
    return <Badge variant="secondary">{t('apiAccess.webhooks.inactive')}</Badge>;
  }
  if (!last) {
    return (
      <span className="text-xs text-muted-foreground">
        {t('apiAccess.webhooks.neverDelivered')}
      </span>
    );
  }

  const ok = last.status === 'delivered';
  return (
    <div className="flex flex-col gap-0.5">
      <Badge variant={ok ? 'success' : 'destructive'} appearance="light">
        {ok
          ? t('apiAccess.webhooks.deliveredStatus', { code: last.response_status ?? '' })
          : t('apiAccess.webhooks.failedStatus', { code: last.response_status ?? '—' })}
      </Badge>
      <span className="text-xs text-muted-foreground">
        {last.event} · {formatMoment(last.attempted_at)}
      </span>
    </div>
  );
}

function AddEndpointDialog({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: (created: CreateMerchantWebhookEndpointResponse) => void;
}) {
  const { t } = useTranslation();
  const create = useCreateWebhookEndpoint();
  const [url, setUrl] = useState('');
  const [label, setLabel] = useState('');
  const [events, setEvents] = useState<WebhookEvent[]>([...WEBHOOK_EVENTS]);

  const reset = () => {
    setUrl('');
    setLabel('');
    setEvents([...WEBHOOK_EVENTS]);
  };

  const toggle = (event: WebhookEvent) =>
    setEvents((current) =>
      current.includes(event)
        ? current.filter((e) => e !== event)
        : [...current, event],
    );

  const submit = () => {
    create.mutate(
      { url: url.trim(), label: label.trim() || undefined, events },
      {
        onSuccess: (created) => {
          reset();
          onCreated(created);
        },
        onError: (error) =>
          toast.error(apiErrorMessage(error, t('apiAccess.webhooks.createFailed'))),
      },
    );
  };

  const valid = /^https:\/\/.+/.test(url.trim()) && events.length > 0;

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('apiAccess.webhooks.addTitle')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-5">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="wh-url">{t('apiAccess.webhooks.urlLabel')}</Label>
            <Input
              id="wh-url"
              dir="ltr"
              placeholder="https://shop.example.mv/manfaa/webhook"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              autoComplete="off"
            />
            <span className="text-xs text-muted-foreground">
              {t('apiAccess.webhooks.urlHint')}
            </span>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="wh-label">{t('apiAccess.webhooks.labelLabel')}</Label>
            <Input
              id="wh-label"
              maxLength={80}
              placeholder={t('apiAccess.webhooks.labelPlaceholder')}
              value={label}
              onChange={(e) => setLabel(e.target.value)}
            />
          </div>

          <div className="flex flex-col gap-2.5">
            <Label>{t('apiAccess.webhooks.eventsLabel')}</Label>
            {WEBHOOK_EVENTS.map((event) => (
              <label key={event} className="flex items-start gap-2.5 text-sm">
                <Checkbox
                  checked={events.includes(event)}
                  onCheckedChange={() => toggle(event)}
                  className="mt-0.5"
                />
                <span className="flex flex-col">
                  <code dir="ltr" className="text-xs">
                    {event}
                  </code>
                  <span className="text-xs text-muted-foreground">
                    {t(`apiAccess.webhooks.events.${event.replace('.', '_')}`)}
                  </span>
                </span>
              </label>
            ))}
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={create.isPending}>
            {t('common.cancel')}
          </Button>
          <Button disabled={!valid || create.isPending} onClick={submit}>
            {t('apiAccess.webhooks.addAction')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function SecretHandoverDialog({
  created,
  onDone,
}: {
  created: CreateMerchantWebhookEndpointResponse;
  onDone: () => void;
}) {
  const { t } = useTranslation();
  const [acknowledged, setAcknowledged] = useState(false);
  const { copyToClipboard } = useCopyToClipboard();

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        // Locked until acknowledged, exactly like the token handover.
        if (!open && acknowledged) onDone();
      }}
    >
      <DialogContent className="max-w-lg" showCloseButton={false}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Plug className="size-4" />
            {t('apiAccess.webhooks.secretTitle')}
          </DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertContent>
              <AlertTitle>{t('apiAccess.tokenOnceTitle')}</AlertTitle>
              <AlertDescription>{t('apiAccess.webhooks.secretOnceBody')}</AlertDescription>
            </AlertContent>
          </Alert>

          <div className="flex items-center gap-2 rounded-md border bg-muted/40 p-3">
            <code dir="ltr" className="text-mono text-xs break-all grow">
              {created.secret}
            </code>
            <Button
              variant="ghost"
              size="sm"
              aria-label={t('apiAccess.webhooks.copySecret')}
              onClick={() => {
                copyToClipboard(created.secret);
                toast.success(t('apiAccess.webhooks.secretCopied'));
              }}
            >
              <Copy />
            </Button>
          </div>

          <p className="text-sm text-muted-foreground">
            {t('apiAccess.webhooks.secretHow')}{' '}
            <a
              href={`${INTEGRATION_GUIDE_URL}#6-webhooks`}
              target="_blank"
              rel="noreferrer"
              className="underline"
            >
              {t('apiAccess.webhooks.secretGuideLink')}
            </a>
          </p>

          <label className="flex items-start gap-2.5 text-sm">
            <Checkbox
              checked={acknowledged}
              onCheckedChange={(value) => setAcknowledged(value === true)}
              className="mt-0.5"
            />
            <span>{t('apiAccess.webhooks.secretAcknowledge')}</span>
          </label>
        </DialogBody>
        <DialogFooter>
          <Button disabled={!acknowledged} onClick={onDone}>
            {t('apiAccess.tokenDone')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function WebhooksSection() {
  const { t } = useTranslation();
  const endpoints = useWebhookEndpoints();
  const remove = useDeleteWebhookEndpoint();
  const sendTest = useTestWebhookEndpoint();

  const [adding, setAdding] = useState(false);
  const [created, setCreated] =
    useState<CreateMerchantWebhookEndpointResponse | null>(null);
  const [removing, setRemoving] = useState<MerchantWebhookEndpoint | null>(null);

  const rows = endpoints.data ?? [];
  const activeCount = rows.filter((row) => row.active).length;
  const atCap = activeCount >= ACTIVE_CAP;

  const confirmRemove = (endpoint: MerchantWebhookEndpoint) => {
    remove.mutate(endpoint.id, {
      onSuccess: () => {
        setRemoving(null);
        toast.success(t('apiAccess.webhooks.removed'));
      },
      onError: (error) =>
        toast.error(apiErrorMessage(error, t('apiAccess.webhooks.removeFailed'))),
    });
  };

  const test = (endpoint: MerchantWebhookEndpoint) => {
    sendTest.mutate(endpoint.id, {
      onSuccess: () => toast.success(t('apiAccess.webhooks.testSent')),
      onError: (error) =>
        toast.error(apiErrorMessage(error, t('apiAccess.webhooks.testFailed'))),
    });
  };

  return (
    <>
      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {t('apiAccess.webhooks.title', { active: activeCount, cap: ACTIVE_CAP })}
          </CardTitle>
          <Button size="sm" disabled={atCap} onClick={() => setAdding(true)}>
            <Plus />
            {t('apiAccess.webhooks.add')}
          </Button>
        </CardHeader>

        {endpoints.isError ? (
          <ErrorBlock error={endpoints.error} fallback={t('apiAccess.webhooks.loadFailed')} />
        ) : !endpoints.data ? (
          <LoadingBlock lines={3} />
        ) : rows.length === 0 ? (
          <EmptyBlock>
            <div className="max-w-md text-center">
              <p className="font-medium text-foreground">{t('apiAccess.webhooks.emptyTitle')}</p>
              <p className="mt-1">{t('apiAccess.webhooks.emptyBody')}</p>
            </div>
          </EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('apiAccess.webhooks.columnEndpoint')}</TableHead>
                    <TableHead>{t('apiAccess.webhooks.columnEvents')}</TableHead>
                    <TableHead>{t('apiAccess.webhooks.columnLastDelivery')}</TableHead>
                    <TableHead className="w-44" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((endpoint) => (
                    <TableRow
                      key={endpoint.id}
                      className={endpoint.active ? undefined : 'opacity-60'}
                    >
                      <TableCell>
                        <div className="flex flex-col gap-1">
                          <span className="font-medium">
                            {endpoint.label ?? t('apiAccess.webhooks.unlabelled')}
                          </span>
                          <code dir="ltr" className="text-xs text-muted-foreground break-all">
                            {endpoint.url}
                          </code>
                          {endpoint.registered_by === 'credential' && (
                            <span className="text-xs text-muted-foreground">
                              <Badge variant="secondary" size="sm">
                                {t('apiAccess.webhooks.byCredential')}
                              </Badge>{' '}
                              {t('apiAccess.webhooks.byCredentialHint')}
                            </span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-1">
                          {endpoint.events.map((event) => (
                            <Badge key={event} variant="outline" size="sm">
                              <code dir="ltr">{event}</code>
                            </Badge>
                          ))}
                        </div>
                      </TableCell>
                      <TableCell>
                        <DeliveryBadge endpoint={endpoint} />
                      </TableCell>
                      <TableCell className="text-end">
                        <div className="flex justify-end gap-1.5">
                          <Button
                            variant="outline"
                            size="sm"
                            disabled={!endpoint.active || sendTest.isPending}
                            onClick={() => test(endpoint)}
                          >
                            <Send />
                            {t('apiAccess.webhooks.sendTest')}
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            aria-label={t('apiAccess.webhooks.remove')}
                            onClick={() => setRemoving(endpoint)}
                          >
                            <Trash2 />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        )}
      </Card>

      <AddEndpointDialog
        open={adding}
        onClose={() => setAdding(false)}
        onCreated={(response) => {
          setAdding(false);
          setCreated(response);
        }}
      />

      {created && (
        <SecretHandoverDialog created={created} onDone={() => setCreated(null)} />
      )}

      <AlertDialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t('apiAccess.webhooks.removeTitle')}</AlertDialogTitle>
            <AlertDialogDescription>
              {t('apiAccess.webhooks.removeBody', {
                url: removing?.url ?? '',
              })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => removing && confirmRemove(removing)}
            >
              {t('apiAccess.webhooks.remove')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
