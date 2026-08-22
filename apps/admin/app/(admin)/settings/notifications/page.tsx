'use client';

import { useState } from 'react';
import {
  listNotificationTemplates,
  updateNotificationTemplate,
  type NotificationTemplate,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LoaderCircle } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { useMarketplaceEnabled } from '@/hooks/use-marketplace-enabled';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

/**
 * What the platform says to customers, and whether it says it at all.
 *
 * There is no "new template" button and no delete. The moments live in code
 * (App\Domain\Notifications\NotificationTemplateKey) beside the calls that
 * fire them, because a template nothing sends would be words nobody ever
 * reads. An admin owns the sentence and the switch.
 *
 * Every notification sends in English, always (decision 2026-08-17) — there
 * is one body per template and it is the one that goes out.
 *
 * Every message costs money per send and cannot be unsubscribed from, so
 * the screen leads with WHEN each one fires and how often — the fact that
 * decides whether to enable it — rather than with the text box.
 */

/** 160 per part for plain GSM; anything outside it forces unicode, where a part is 70. */
function segmentsFor(body: string): { count: number; unicode: boolean } {
  const unicode = /[^ -~]/.test(body);
  const per = unicode ? 70 : 160;

  return { count: Math.max(1, Math.ceil(body.length / per)), unicode };
}

function TemplateCard({ template }: { template: NotificationTemplate }) {
  const queryClient = useQueryClient();
  const [bodyEn, setBodyEn] = useState(template.body_en);

  const dirty = bodyEn !== template.body_en;

  const invalidate = () =>
    void queryClient.invalidateQueries({
      queryKey: ['admin', 'notification-templates'],
    });

  const save = useMutation({
    mutationFn: () =>
      updateNotificationTemplate(template.id, { body_en: bodyEn }),
    onSuccess: () => {
      invalidate();
      toast.success('Message saved.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const toggle = useMutation({
    mutationFn: (active: boolean) =>
      updateNotificationTemplate(template.id, { active }),
    onSuccess: (response) => {
      invalidate();
      toast.success(
        response.data.active
          ? 'Switched on — customers will start receiving this.'
          : 'Switched off. Nothing more will be sent.',
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const segments = segmentsFor(bodyEn);

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="flex flex-col gap-1">
          <CardTitle className="flex flex-wrap items-center gap-2">
            {template.label}
            <Badge
              variant={template.active ? 'success' : 'secondary'}
              appearance="light"
              size="sm"
            >
              {template.active ? 'Sending' : 'Off'}
            </Badge>
          </CardTitle>
          <p className="max-w-xl text-sm text-muted-foreground">
            {template.description}
          </p>
        </div>
        <Switch
          checked={template.active}
          disabled={toggle.isPending}
          onCheckedChange={(next) => toggle.mutate(next)}
          aria-label={`Send ${template.label}`}
        />
      </CardHeader>

      <CardContent className="flex flex-col gap-5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs text-muted-foreground">Available:</span>
          {template.variables.map((variable) => (
            <Badge
              key={variable.token}
              variant="secondary"
              appearance="light"
              size="sm"
              title={variable.description}
            >
              {`{{${variable.token}}}`}
            </Badge>
          ))}
        </div>

        <div className="flex flex-col gap-2.5">
          <Label htmlFor={`en-${template.id}`}>Message</Label>
          <Textarea
            id={`en-${template.id}`}
            rows={3}
            maxLength={480}
            value={bodyEn}
            onChange={(event) => setBodyEn(event.target.value)}
          />
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-xs text-muted-foreground">
            {/* The count is the cost: an SMS is billed per 160-character
                part (70 once anything pushes it to unicode). */}
            {bodyEn.length} characters · {segments.count} SMS
            {segments.count === 1 ? '' : ' parts'} per send
            {segments.unicode ? ' (unicode — 70 per part)' : ''}
          </p>
          <Button
            size="sm"
            disabled={!dirty || save.isPending}
            onClick={() => save.mutate()}
          >
            {save.isPending && <LoaderCircle className="animate-spin" />}
            Save message
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

export default function NotificationsSettingsPage() {
  // Undefined while loading counts as ON, so the list does not flash the
  // marketplace moments away on every page load.
  const marketplace = useMarketplaceEnabled() ?? true;

  const query = useQuery({
    queryKey: ['admin', 'notification-templates'],
    queryFn: ({ signal }) => listNotificationTemplates({ signal }),
    select: (response) => response.data,
  });

  // The order moments and the enrolment outcomes cannot fire with the
  // marketplace off — every route behind them refuses — so listing nine
  // templates nothing can send describes a platform this one is not. The
  // rows still exist; an admin can write the copy before launch by turning
  // the marketplace on.
  const templates = (query.data ?? []).filter(
    (template) => marketplace || !template.marketplace_only,
  );

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-mono">
          Customer notifications
        </h1>
        <p className="max-w-3xl text-sm text-muted-foreground">
          The SMS messages Manfaa sends to customers — always in English. Each
          one is billed per send and cannot be unsubscribed from, so they arrive
          switched off unless there is a reason otherwise. The moments
          themselves are fixed in code — you write the words and decide whether
          they go.
        </p>
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-5">
          <Skeleton className="h-64 w-full" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : (
        templates.map((template) => (
          <TemplateCard key={template.id} template={template} />
        ))
      )}
    </div>
  );
}
