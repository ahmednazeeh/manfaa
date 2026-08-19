'use client';

import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Upload } from 'lucide-react';
import {
  apiErrorMessage,
  useEnrolInMarketplace,
  useMarketplaceEnrolment,
  useSubmitMarketplaceApplication,
} from '@/lib/queries';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * Opting in to the marketplace (PLAN-marketplace.md §9).
 *
 * Business type, how the shop fulfils, and the papers. A store that never
 * comes here simply sells cashback in person, which is the ordinary case —
 * marketplace is optional.
 */
const DOCUMENTS = [
  { kind: 'business_registration', label: 'Business registration', required: true },
  { kind: 'owner_id', label: 'Owner ID', required: true },
  { kind: 'bank_letter', label: 'Bank letter', required: true },
  { kind: 'tin_certificate', label: 'TIN certificate', required: false },
];

export default function MarketplaceSetupPage() {
  const { t } = useTranslation();
  const enrolment = useMarketplaceEnrolment();
  const enrol = useEnrolInMarketplace();
  const submit = useSubmitMarketplaceApplication();

  const data = enrolment.data?.data;

  const [form, setForm] = useState({
    business_type: 'pvt_ltd',
    fulfilment: 'both',
    prep_time_min: '',
    prep_time_max: '',
  });

  useEffect(() => {
    if (!data) return;
    setForm({
      business_type: data.business_type ?? 'pvt_ltd',
      fulfilment: data.fulfilment ?? 'both',
      prep_time_min: data.prep_time_min?.toString() ?? '',
      prep_time_max: data.prep_time_max?.toString() ?? '',
    });
  }, [data]);

  if (enrolment.isPending) return <ScreenLoader />;

  if (enrolment.isError || !data) {
    return (
      <Alert variant="warning" appearance="light">
        <AlertTitle>The marketplace is not open yet</AlertTitle>
        <AlertDescription>
          Manfaa has not switched on the marketplace. Nothing to do here for
          now — your cashback business is unaffected.
        </AlertDescription>
      </Alert>
    );
  }

  const live = data.state === 'active';

  return (
    <div className="flex flex-col gap-5">
      <Card>
        <CardHeader>
          <CardTitle>Marketplace</CardTitle>
          <Badge
            variant={
              live ? 'success' : data.state === 'rejected' ? 'destructive' : 'warning'
            }
            appearance="light"
          >
            {live
              ? 'Selling'
              : data.state === 'rejected'
                ? 'Not approved'
                : data.state === 'not_enrolled'
                  ? 'Not started'
                  : 'Waiting for review'}
          </Badge>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <p className="text-sm text-muted-foreground">
            Sell online through the Manfaa app. Customers order, pay Manfaa,
            and we transfer you the money after cashback and the{' '}
            {data.order_fee_percent}% marketplace fee.
          </p>

          {data.rejected_reason ? (
            <Alert variant="destructive" appearance="light">
              <AlertTitle>Your application was not approved</AlertTitle>
              <AlertDescription>{data.rejected_reason}</AlertDescription>
            </Alert>
          ) : null}

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label htmlFor="business-type">Business type</Label>
              <select
                id="business-type"
                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                value={form.business_type}
                onChange={(event) =>
                  setForm({ ...form, business_type: event.target.value })
                }
              >
                <option value="sole_prop">Sole proprietorship</option>
                <option value="partnership">Partnership</option>
                <option value="pvt_ltd">Private limited</option>
                <option value="cooperative">Cooperative</option>
              </select>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="fulfilment">How you fulfil orders</Label>
              <select
                id="fulfilment"
                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                value={form.fulfilment}
                onChange={(event) =>
                  setForm({ ...form, fulfilment: event.target.value })
                }
              >
                <option value="delivery">Delivery only</option>
                <option value="pickup">Pickup only</option>
                <option value="both">Delivery and pickup</option>
              </select>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="prep-min">Preparation time (minutes)</Label>
              <div className="flex items-center gap-2">
                <Input
                  id="prep-min"
                  inputMode="numeric"
                  className="w-24"
                  placeholder="30"
                  value={form.prep_time_min}
                  onChange={(event) =>
                    setForm({ ...form, prep_time_min: event.target.value })
                  }
                />
                <span className="text-sm text-muted-foreground">to</span>
                <Input
                  inputMode="numeric"
                  className="w-24"
                  placeholder="60"
                  value={form.prep_time_max}
                  onChange={(event) =>
                    setForm({ ...form, prep_time_max: event.target.value })
                  }
                />
              </div>
              <p className="text-xs text-muted-foreground">
                Shown to customers as your delivery estimate.
              </p>
            </div>
          </div>

          <div>
            <Button
              disabled={enrol.isPending}
              onClick={() =>
                enrol.mutate(
                  {
                    business_type: form.business_type,
                    fulfilment: form.fulfilment,
                    prep_time_min: form.prep_time_min === '' ? null : Number(form.prep_time_min),
                    prep_time_max: form.prep_time_max === '' ? null : Number(form.prep_time_max),
                  },
                  {
                    onSuccess: () => toast.success('Saved.'),
                    onError: (error) =>
                      toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
                  },
                )
              }
            >
              {enrol.isPending ? 'Saving…' : data.state === 'not_enrolled' ? 'Opt in' : 'Save'}
            </Button>
          </div>
        </CardContent>
      </Card>

      {data.state !== 'not_enrolled' ? (
        <Card>
          <CardHeader>
            <CardTitle>Business verification</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
              We need these before you can sell. They are stored privately and
              only Manfaa staff reviewing your application can open them.
            </p>

            <div className="flex flex-col gap-3">
              {DOCUMENTS.map((document) => {
                const held = data.documents.find((row) => row.kind === document.kind);

                return (
                  <DocumentRow
                    key={document.kind}
                    kind={document.kind}
                    label={document.label}
                    required={document.required}
                    held={held}
                  />
                );
              })}
            </div>

            {data.missing_documents.length > 0 ? (
              <Alert variant="warning" appearance="light" size="sm">
                <AlertDescription>
                  Upload every required paper before submitting — an
                  incomplete application waits in the queue without being
                  looked at.
                </AlertDescription>
              </Alert>
            ) : null}

            {!live ? (
              <div>
                <Button
                  disabled={data.missing_documents.length > 0 || submit.isPending}
                  onClick={() =>
                    submit.mutate(undefined, {
                      onSuccess: () => toast.success('Sent for review.'),
                      onError: (error) =>
                        toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
                    })
                  }
                >
                  {submit.isPending ? 'Sending…' : 'Submit for review'}
                </Button>
              </div>
            ) : null}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}

function DocumentRow({
  kind,
  label,
  required,
  held,
}: {
  kind: string;
  label: string;
  required: boolean;
  held?: { id: number; original_name: string; state: string };
}) {
  const [busy, setBusy] = useState(false);

  const upload = async (file: File) => {
    setBusy(true);
    try {
      const body = new FormData();
      body.append('kind', kind);
      body.append('file', file);

      const response = await fetch('/api/merchant/marketplace/documents', {
        method: 'POST',
        body,
        credentials: 'include',
      });

      if (!response.ok) {
        const json = await response.json().catch(() => ({}));
        throw new Error(json.message ?? 'That file could not be uploaded.');
      }

      toast.success(`${label} uploaded.`);
      window.location.reload();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Upload failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3">
      <div className="min-w-0">
        <p className="text-sm font-medium">
          {label}
          {required ? null : (
            <span className="ms-2 text-xs text-muted-foreground">optional</span>
          )}
        </p>
        {held ? (
          <p className="text-xs text-muted-foreground">
            {held.original_name} · {held.state}
          </p>
        ) : (
          <p className="text-xs text-muted-foreground">Not uploaded yet.</p>
        )}
      </div>
      <Button size="sm" variant="outline" asChild disabled={busy}>
        <label className="cursor-pointer">
          <Upload className="size-4" />
          {busy ? 'Uploading…' : held ? 'Replace' : 'Upload'}
          <input
            type="file"
            accept=".jpg,.jpeg,.png,.webp,.pdf"
            className="hidden"
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (file) void upload(file);
              event.target.value = '';
            }}
          />
        </label>
      </Button>
    </div>
  );
}
