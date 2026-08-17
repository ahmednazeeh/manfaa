'use client';

import { useEffect, useState } from 'react';
import { getAdminBrandColor, setAdminBrandColor } from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, LoaderCircle, RotateCcw } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/admin/page-header';

/**
 * The storefront accent, as a superadmin lever (owner, 2026-08-17 — born of
 * the teal↔coral debate). The admin picks ONE colour; the customer web
 * derives its whole light/dark token set from the hue with a
 * lightness-governed recipe, so no choice here can produce unreadable
 * text. "Default" clears the override and the storefront returns to its
 * built-in lagoon teal.
 */

const PRESETS: { name: string; hex: string }[] = [
  { name: 'Lagoon (default)', hex: '' }, // empty = clear the override
  { name: 'Coral', hex: '#e8355f' },
  { name: 'Ocean', hex: '#1466b8' },
  { name: 'Emerald', hex: '#0f8a5f' },
  { name: 'Violet', hex: '#7c3aed' },
  { name: 'Sunset', hex: '#d97706' },
];

export default function AppearancePage() {
  const queryClient = useQueryClient();

  const brand = useQuery({
    queryKey: ['admin', 'brand-color'],
    queryFn: async ({ signal }) => (await getAdminBrandColor({ signal })).data,
  });

  const [picked, setPicked] = useState<string | null>(null);

  // Follow the server value until the admin touches the picker.
  const current = brand.data?.color ?? null;
  const [dirty, setDirty] = useState(false);
  useEffect(() => {
    if (!dirty) setPicked(current);
  }, [current, dirty]);

  const save = useMutation({
    mutationFn: (color: string | null) => setAdminBrandColor(color),
    onSuccess: (response) => {
      queryClient.setQueryData(['admin', 'brand-color'], response.data);
      setDirty(false);
      toast.success(
        response.data.color
          ? 'Storefront colour updated — customers see it within a minute.'
          : 'Storefront colour reset to the default lagoon.',
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const choose = (hex: string | null) => {
    setPicked(hex);
    setDirty(true);
  };

  const value = picked ?? '';
  const validHex = value === '' || /^#[0-9a-f]{6}$/i.test(value);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Appearance"
        description="The accent colour the customer storefront and dashboard wear. Contrast is governed automatically — any hue stays readable."
      />

      <Card className="max-w-2xl">
        <CardHeader>
          <CardTitle>Storefront accent</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-6 p-6">
          <div className="flex flex-wrap gap-3">
            {PRESETS.map((preset) => {
              const selected =
                preset.hex === '' ? value === '' : value === preset.hex;
              return (
                <button
                  key={preset.name}
                  type="button"
                  onClick={() => choose(preset.hex === '' ? null : preset.hex)}
                  className="flex flex-col items-center gap-1.5 group"
                >
                  <span
                    className="size-10 rounded-full border border-border inline-flex items-center justify-center group-hover:scale-105 transition-transform"
                    style={{
                      background:
                        preset.hex === ''
                          ? 'oklch(0.48 0.12 195)'
                          : preset.hex,
                    }}
                  >
                    {selected && <Check className="size-4 text-white" />}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {preset.name}
                  </span>
                </button>
              );
            })}
          </div>

          <div className="flex items-center gap-3">
            <input
              type="color"
              aria-label="Custom colour"
              value={validHex && value !== '' ? value : '#0f7e8b'}
              onChange={(event) => choose(event.target.value)}
              className="size-9 rounded-md border border-border cursor-pointer bg-transparent p-0.5"
            />
            <Input
              value={value}
              onChange={(event) => choose(event.target.value || null)}
              placeholder="Default (lagoon)"
              className="w-36 font-mono"
              dir="ltr"
            />
            {!validHex && (
              <span className="text-xs text-destructive">
                Use #rrggbb format.
              </span>
            )}
          </div>

          <div className="flex items-center gap-2.5">
            <Button
              disabled={!dirty || !validHex || save.isPending}
              onClick={() => save.mutate(value === '' ? null : value.toLowerCase())}
            >
              {save.isPending && <LoaderCircle className="animate-spin" />}
              Save
            </Button>
            {current !== null && (
              <Button
                variant="outline"
                disabled={save.isPending}
                onClick={() => save.mutate(null)}
              >
                <RotateCcw />
                Reset to default
              </Button>
            )}
          </div>

          <p className="text-xs text-muted-foreground">
            Applies to manfaa.app (storefront and customer dashboard) within a
            minute. The admin and merchant panels are unaffected.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
