'use client';

import { useRef, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  createZone,
  deleteZone,
  listZones,
  reorderZones,
  updateZone,
  type Zone,
  type ZonePoint,
} from '@manfaa/api-client';
import { type GMapsApi } from '@manfaa/ui';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ChevronDown,
  ChevronUp,
  Pencil,
  PenLine,
  Trash2,
  TriangleAlert,
} from 'lucide-react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { apiErrorMessage } from '@/lib/api-error';
import { centroidOf, islandNameFrom } from '@/lib/zones';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';
import { zoneColor, ZonesMap } from '@/components/zones/zones-map';

const QUERY_KEY = ['admin', 'zones'] as const;

const FormSchema = z.object({
  name: z.string().max(100, 'At most 100 characters.'),
  name_dv: z.string().max(100, 'At most 100 characters.'),
});
type FormValues = z.infer<typeof FormSchema>;

/**
 * One editing session: a zone being created from a fresh ring, or an
 * existing one being renamed and/or redrawn. Null means the page is just a
 * viewer.
 */
type Session = { kind: 'create' } | { kind: 'edit'; zone: Zone };

function DeleteZoneAction({
  zone,
  onDeleted,
}: {
  zone: Zone;
  onDeleted: (id: number) => void;
}) {
  const queryClient = useQueryClient();
  const remove = useMutation({
    mutationFn: () => deleteZone(zone.id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: QUERY_KEY });
      toast.success(`Zone “${zone.name}” deleted.`);
      onDeleted(zone.id);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button variant="outline" size="sm" disabled={remove.isPending}>
          <Trash2 />
          Delete
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Delete “{zone.name}”?</AlertDialogTitle>
          <AlertDialogDescription>
            The polygon is removed and branches are reassigned: the{' '}
            {zone.branch_count === 1
              ? '1 branch inside it is'
              : `${zone.branch_count} branches inside it are`}{' '}
            released, and re-assigned to another zone only where polygons
            overlap. Customers lose this island as a discovery filter.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            variant="destructive"
            onClick={() => remove.mutate()}
          >
            Delete zone
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

export default function ZonesPage() {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: ({ signal }) => listZones({ signal }),
  });
  const zones = query.data?.data ?? [];

  const [session, setSession] = useState<Session | null>(null);
  const [drawing, setDrawing] = useState(false);
  const [draft, setDraft] = useState<ZonePoint[] | null>(null);
  const [mapUnavailable, setMapUnavailable] = useState(false);
  const mapsApiRef = useRef<GMapsApi | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(FormSchema),
    defaultValues: { name: '', name_dv: '' },
  });

  const editing = session?.kind === 'edit' ? session.zone : null;

  const startCreate = () => {
    setSession({ kind: 'create' });
    setDraft(null);
    setDrawing(true);
    form.reset({ name: '', name_dv: '' });
  };

  const startEdit = (zone: Zone) => {
    setSession({ kind: 'edit', zone });
    setDraft(null);
    setDrawing(false);
    form.reset({ name: zone.name, name_dv: zone.name_dv ?? '' });
  };

  const endSession = () => {
    setSession(null);
    setDrawing(false);
    setDraft(null);
  };

  /**
   * The skipped-name path: reverse-geocode the ring's centroid and take the
   * most specific locality-ish component. Null when the geocoder is missing,
   * refuses, or returns nothing usable — the caller must then insist on a
   * typed name, because the API never accepts an empty one.
   */
  const lookupIslandName = async (
    polygon: ZonePoint[],
  ): Promise<string | null> => {
    const maps = mapsApiRef.current;
    if (maps === null) return null;
    try {
      const { results } = await new maps.Geocoder().geocode({
        location: centroidOf(polygon),
      });
      return islandNameFrom(results);
    } catch {
      return null;
    }
  };

  const save = useMutation({
    mutationFn: async (values: FormValues) => {
      if (session === null) throw new Error('Nothing to save.');
      const polygon =
        draft ?? (session.kind === 'edit' ? session.zone.polygon : null);
      if (polygon === null) {
        throw new Error('Draw the zone on the map first.');
      }

      let name = values.name.trim();
      if (name === '') {
        name = (await lookupIslandName(polygon)) ?? '';
      }
      if (name === '') {
        throw new Error(
          "Couldn't read the island's name from the map — type a name and save again.",
        );
      }

      const body = {
        name,
        name_dv: values.name_dv.trim() === '' ? null : values.name_dv.trim(),
        polygon,
      };
      return session.kind === 'edit'
        ? updateZone(session.zone.id, body)
        : createZone(body);
    },
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: QUERY_KEY });
      toast.success(
        session?.kind === 'edit'
          ? `Zone “${response.data.name}” updated — branches reassigned.`
          : `Zone “${response.data.name}” created — branches inside it are assigned.`,
      );
      endSession();
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  // Display order (owner request 2026-08-17): the arrows swap neighbours and
  // submit the WHOLE id list — the server refuses anything partial. The
  // app's island picker mirrors this order, so the answer is written back
  // into the cache immediately rather than waiting on a refetch.
  const reorder = useMutation({
    mutationFn: (ids: number[]) => reorderZones(ids),
    onSuccess: (response) => {
      queryClient.setQueryData(QUERY_KEY, response);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const moveZone = (index: number, delta: -1 | 1) => {
    const ids = zones.map((zone) => zone.id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    reorder.mutate(ids);
  };

  const polygonPending = session?.kind === 'create' && draft === null;

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Zones"
        description="Island zones for discovery: draw a polygon around an island and every merchant branch pinned inside it is assigned to the zone automatically on save."
        actions={
          <Button
            onClick={startCreate}
            disabled={mapUnavailable || session !== null}
          >
            <PenLine />
            Draw zone
          </Button>
        }
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
          <ZonesMap
            zones={zones}
            draft={draft}
            dimmedZoneId={
              editing !== null && (drawing || draft !== null)
                ? editing.id
                : null
            }
            drawing={drawing}
            onReady={(maps) => {
              mapsApiRef.current = maps;
            }}
            onUnavailable={() => setMapUnavailable(true)}
            onPolygonComplete={(polygon) => {
              setDraft(polygon);
              setDrawing(false);
            }}
            className="h-[420px] lg:h-[560px]"
          />

          <div className="flex flex-col gap-5">
            {session !== null && (
              <Card>
                <CardHeader>
                  <CardTitle>
                    {editing !== null ? `Edit “${editing.name}”` : 'New zone'}
                  </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-4 py-4">
                  <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                    <span>
                      {drawing
                        ? editing !== null
                          ? 'Drawing the replacement ring on the map…'
                          : 'Drawing on the map…'
                        : draft !== null
                          ? `Ring captured — ${draft.length} points.`
                          : editing !== null
                            ? `Keeping the current polygon (${editing.polygon.length} points).`
                            : 'No ring yet.'}
                    </span>
                    {!drawing && (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={mapUnavailable}
                        onClick={() => {
                          setDraft(null);
                          setDrawing(true);
                        }}
                      >
                        <PenLine />
                        {draft !== null || editing !== null
                          ? 'Redraw polygon'
                          : 'Draw polygon'}
                      </Button>
                    )}
                  </div>

                  <Form {...form}>
                    <form
                      id="zone-form"
                      onSubmit={form.handleSubmit((values) =>
                        save.mutate(values),
                      )}
                      className="flex flex-col gap-4"
                    >
                      <FormField
                        control={form.control}
                        name="name"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel>Name</FormLabel>
                            <FormControl>
                              <Input
                                placeholder="Leave empty to use the island's name from the map"
                                {...field}
                              />
                            </FormControl>
                            <FormDescription>
                              Left empty, the island’s name is looked up from
                              the map when you save.
                            </FormDescription>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <FormField
                        control={form.control}
                        name="name_dv"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel>Name (Dhivehi)</FormLabel>
                            <FormControl>
                              <Input
                                dir="rtl"
                                lang="dv"
                                placeholder="ދިވެހި ނަން"
                                {...field}
                              />
                            </FormControl>
                            <FormDescription>
                              Optional — the English name shows wherever this is
                              empty.
                            </FormDescription>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          onClick={endSession}
                          disabled={save.isPending}
                        >
                          Cancel
                        </Button>
                        <Button
                          type="submit"
                          disabled={save.isPending || drawing || polygonPending}
                        >
                          {save.isPending
                            ? 'Saving…'
                            : editing !== null
                              ? 'Save changes'
                              : 'Save zone'}
                        </Button>
                      </div>
                    </form>
                  </Form>
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader>
                <CardTitle>Zones</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col divide-y divide-border py-0">
                {query.isPending ? (
                  Array.from({ length: 3 }).map((_, index) => (
                    <div key={index} className="py-3">
                      <Skeleton className="h-6 w-full" />
                    </div>
                  ))
                ) : zones.length === 0 ? (
                  <p className="py-6 text-center text-sm text-muted-foreground">
                    No zones yet. Draw the first island.
                  </p>
                ) : (
                  zones.map((zone, index) => (
                    <div key={zone.id} className="flex items-center gap-3 py-3">
                      <span
                        aria-hidden
                        className="size-3 shrink-0 rounded-full"
                        style={{ backgroundColor: zoneColor(index) }}
                      />
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-medium">
                          {zone.name}
                        </div>
                        <div className="flex items-baseline gap-2 text-xs text-muted-foreground">
                          {zone.name_dv ? (
                            <span dir="rtl" lang="dv">
                              {zone.name_dv}
                            </span>
                          ) : null}
                          <span>
                            {zone.branch_count === 1
                              ? '1 branch'
                              : `${zone.branch_count} branches`}
                          </span>
                        </div>
                      </div>
                      <div className="flex shrink-0 items-center gap-1.5">
                        <div className="flex flex-col">
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-5 px-1"
                            aria-label={`Move ${zone.name} up`}
                            disabled={index === 0 || reorder.isPending}
                            onClick={() => moveZone(index, -1)}
                          >
                            <ChevronUp className="size-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-5 px-1"
                            aria-label={`Move ${zone.name} down`}
                            disabled={
                              index === zones.length - 1 || reorder.isPending
                            }
                            onClick={() => moveZone(index, 1)}
                          >
                            <ChevronDown className="size-3.5" />
                          </Button>
                        </div>
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={save.isPending}
                          onClick={() => startEdit(zone)}
                        >
                          <Pencil />
                          Edit
                        </Button>
                        <DeleteZoneAction
                          zone={zone}
                          onDeleted={(id) => {
                            // The zone on the operating table just vanished.
                            if (editing?.id === id) endSession();
                          }}
                        />
                      </div>
                    </div>
                  ))
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      )}
    </div>
  );
}
