'use client';

import { useEffect, useState } from 'react';
import { type BranchDeliveryRow } from '@manfaa/api-client';
import { formatMoney } from '@manfaa/ui';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
  apiErrorMessage,
  useBranchDelivery,
  useBranches,
  useRemoveBranchDelivery,
  useSaveBranchDelivery,
} from '@/lib/queries';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScreenLoader } from '@/components/screen-loader';

/**
 * Delivery terms, per SHOP and per island (PLAN-marketplace.md §2.4).
 *
 * The owner's case: a Malé shop wants a low free-delivery minimum to Malé
 * and a high one to Hulhumalé, and a Hulhumalé shop wants the mirror image.
 * That is not a merchant-level setting, which is why this screen asks you to
 * pick a shop first.
 */
export default function DeliveryPage() {
  const branches = useBranches();
  const [branchId, setBranchId] = useState<number | null>(null);

  useEffect(() => {
    if (branchId === null && (branches.data?.length ?? 0) > 0) {
      setBranchId(branches.data![0].id);
    }
  }, [branches.data, branchId]);

  const rules = useBranchDelivery(branchId);

  if (branches.isPending) return <ScreenLoader />;

  return (
    <div className="flex flex-col gap-5">
      <Alert variant="info" appearance="light" size="sm">
        <AlertDescription>
          Terms belong to the SHOP, not the business — your Malé branch can
          charge differently to Hulhumalé than your Hulhumalé branch does. A
          customer only sees a shop that serves their island.
        </AlertDescription>
      </Alert>

      <div className="flex flex-wrap gap-2">
        {(branches.data ?? []).map((branch) => (
          <Button
            key={branch.id}
            size="sm"
            variant={branchId === branch.id ? 'primary' : 'outline'}
            onClick={() => setBranchId(branch.id)}
          >
            {branch.name}
          </Button>
        ))}
      </div>

      {rules.isPending ? (
        <ScreenLoader />
      ) : rules.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertDescription>
            {apiErrorMessage(rules.error, 'Could not load delivery terms.')}
          </AlertDescription>
        </Alert>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Islands this shop delivers to</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {(rules.data?.data ?? []).map((row) => (
              <IslandRow key={row.zone_id} branchId={branchId!} row={row} />
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function IslandRow({ branchId, row }: { branchId: number; row: BranchDeliveryRow }) {
  const { t } = useTranslation();
  const save = useSaveBranchDelivery();
  const remove = useRemoveBranchDelivery();

  const [form, setForm] = useState({
    fee: row.delivery_fee_laari != null ? (row.delivery_fee_laari / 100).toFixed(2) : '0.00',
    free: row.free_delivery_over_laari != null ? (row.free_delivery_over_laari / 100).toFixed(2) : '',
    minimum: row.order_minimum_laari != null ? (row.order_minimum_laari / 100).toFixed(2) : '',
    etaMin: row.eta_min?.toString() ?? '',
    etaMax: row.eta_max?.toString() ?? '',
  });

  const laari = (value: string): number | null =>
    value.trim() === '' ? null : Math.round(Number(value) * 100);

  return (
    <div className="flex flex-col gap-3 rounded-lg border border-border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{row.zone_name}</span>
        <Badge
          variant={row.delivers ? 'success' : 'secondary'}
          appearance="light"
          size="sm"
        >
          {/* The absence of a rule IS the answer. */}
          {row.delivers ? 'Delivering' : 'Not delivering'}
        </Badge>
        {row.delivers && row.free_delivery_over_laari != null ? (
          <span className="text-xs text-muted-foreground">
            Free over {formatMoney(row.free_delivery_over_laari)}
          </span>
        ) : null}
      </div>

      <div className="grid gap-3 sm:grid-cols-5">
        <Field
          id={`fee-${branchId}-${row.zone_id}`}
          label="Delivery fee"
          value={form.fee}
          onChange={(value) => setForm({ ...form, fee: value })}
        />
        <Field
          id={`free-${branchId}-${row.zone_id}`}
          label="Free over"
          placeholder="never"
          value={form.free}
          onChange={(value) => setForm({ ...form, free: value })}
        />
        <Field
          id={`min-${branchId}-${row.zone_id}`}
          label="Won't deliver below"
          placeholder="no floor"
          value={form.minimum}
          onChange={(value) => setForm({ ...form, minimum: value })}
        />
        <Field
          id={`etamin-${branchId}-${row.zone_id}`}
          label="ETA from (min)"
          value={form.etaMin}
          onChange={(value) => setForm({ ...form, etaMin: value })}
        />
        <Field
          id={`etamax-${branchId}-${row.zone_id}`}
          label="ETA to (min)"
          value={form.etaMax}
          onChange={(value) => setForm({ ...form, etaMax: value })}
        />
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          size="sm"
          disabled={save.isPending}
          onClick={() =>
            save.mutate(
              {
                branchId,
                body: {
                  zone_id: row.zone_id,
                  delivery_fee_laari: laari(form.fee) ?? 0,
                  free_delivery_over_laari: laari(form.free),
                  order_minimum_laari: laari(form.minimum),
                  eta_min: form.etaMin === '' ? null : Number(form.etaMin),
                  eta_max: form.etaMax === '' ? null : Number(form.etaMax),
                },
              },
              {
                onSuccess: () => toast.success(`${row.zone_name} saved.`),
                onError: (error) =>
                  toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
              },
            )
          }
        >
          {row.delivers ? 'Save' : 'Start delivering here'}
        </Button>

        {row.delivers ? (
          <Button
            size="sm"
            variant="ghost"
            onClick={() =>
              remove.mutate(
                { branchId, zoneId: row.zone_id },
                {
                  onSuccess: () =>
                    toast.success(`No longer delivering to ${row.zone_name}.`),
                  onError: (error) =>
                    toast.error(apiErrorMessage(error, t('common.errorGeneric'))),
                },
              )
            }
          >
            Stop delivering here
          </Button>
        ) : null}
      </div>
    </div>
  );
}

function Field({
  id,
  label,
  value,
  placeholder,
  onChange,
}: {
  id: string;
  label: string;
  value: string;
  placeholder?: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="flex flex-col gap-1">
      <Label htmlFor={id} className="text-xs">
        {label}
      </Label>
      <Input
        id={id}
        inputMode="decimal"
        placeholder={placeholder}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  );
}
