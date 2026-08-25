'use client';

import { useMemo, useState } from 'react';
import { type DashboardSeriesEntry } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import { type SeriesColor } from '@/lib/chart-palette';
import { compactMvr, formatBusinessDay } from '@/lib/dashboard';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

/**
 * ONE DAILY CHART, TWO SERIES, ONE AXIS.
 *
 * FORM. The job is trend over time with the series' identity mattering, so
 * the form is a multi-line chart: two 2px lines on ONE y-axis, never a second
 * scale. A period may be up to 366 days long — columns become slivers at that
 * width, lines do not — and the rows arrive zero-filled from the server, so a
 * quiet week draws as a floor at zero rather than as a straight line across
 * the gap that would read as trade.
 *
 * ONE AXIS IS NOT A COMPROMISE HERE: both series on a chart are integer laari
 * of the same currency, so they belong on one scale by right. Measures that
 * are NOT comparable — an accrual against a cash movement — are put on a
 * SEPARATE chart rather than a second axis, because the alignment of two
 * y-scales is arbitrary and invents a correlation the data does not contain.
 *
 * COLOUR follows the measure, not its position: see lib/chart-palette.ts.
 * Every colour is a documented categorical slot, validated against this app's
 * own two chart surfaces in both modes.
 *
 * THE TABLE VIEW IS NOT OPTIONAL. Two of the four slots sit under 3:1 against
 * white, which the palette check flags as needing relief — a channel that
 * states every value without relying on the colour of a mark. The Table
 * toggle is that channel, and it is also what keeps the chart usable for a
 * reader who cannot separate the two lines at all. Deleting it would leave
 * the palette non-compliant.
 *
 * The hover layer is part of the chart, not an upgrade: a crosshair finds the
 * day, and ONE tooltip lists BOTH series at it, so the pointer never has to
 * land on a 2px line to read a number.
 */

/** Every plottable column of a series row — `date` is the axis, not a series. */
export type DailySeriesKey = Exclude<keyof DashboardSeriesEntry, 'date'>;

export interface DailySeries {
  key: DailySeriesKey;
  label: string;
  color: SeriesColor;
}

/** Integer laari or nothing — MoneyText refuses anything else, by design. */
function laariOrNull(value: unknown): number | null {
  const laari = typeof value === 'number' ? value : Number(value);
  return Number.isSafeInteger(laari) ? laari : null;
}

function Amount({ value }: { value: unknown }) {
  const laari = laariOrNull(value);
  return laari === null ? (
    <span className="text-muted-foreground">—</span>
  ) : (
    <MoneyText laari={laari} />
  );
}

export function DailySeriesChart({
  title,
  description,
  entries,
  series,
}: {
  title: string;
  /** What these lines are dated by — the reader cannot infer it. */
  description: string;
  entries: DashboardSeriesEntry[];
  series: DailySeries[];
}) {
  const [view, setView] = useState<'chart' | 'table'>('chart');

  const config = useMemo<ChartConfig>(() => {
    const built: ChartConfig = {};
    for (const line of series) {
      built[line.key] = {
        label: line.label,
        theme: { light: line.color.light, dark: line.color.dark },
      };
    }
    return built;
  }, [series]);

  return (
    <Card>
      <CardHeader className="flex-wrap gap-2 py-3">
        <div className="flex min-w-0 flex-col gap-0.5">
          <CardTitle>{title}</CardTitle>
          <span className="text-xs font-normal text-muted-foreground">
            {description}
          </span>
        </div>

        <ToggleGroup
          type="single"
          size="sm"
          variant="outline"
          value={view}
          onValueChange={(value) => {
            // Radix hands back '' when the pressed item is toggled off; the
            // view is never "neither", so an empty value keeps the current one.
            if (value === 'chart' || value === 'table') {
              setView(value);
            }
          }}
        >
          <ToggleGroupItem value="chart" aria-label="Show as a chart">
            Chart
          </ToggleGroupItem>
          <ToggleGroupItem value="table" aria-label="Show as a table">
            Table
          </ToggleGroupItem>
        </ToggleGroup>
      </CardHeader>

      <CardContent className="py-4">
        {entries.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No days in this period.
          </p>
        ) : view === 'table' ? (
          // Its own horizontal scroll — the page itself never scrolls sideways.
          <div className="max-h-80 overflow-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-32">Day</TableHead>
                  {series.map((line) => (
                    <TableHead key={line.key} className="text-end">
                      {line.label}
                    </TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {entries.map((entry) => (
                  <TableRow key={entry.date}>
                    <TableCell className="whitespace-nowrap">
                      {formatBusinessDay(entry.date, 'long')}
                    </TableCell>
                    {series.map((line) => (
                      <TableCell
                        key={line.key}
                        className="text-end whitespace-nowrap"
                      >
                        <Amount value={entry[line.key]} />
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        ) : (
          <>
            <span className="mb-1 block text-[0.6875rem] text-muted-foreground">
              MVR per day
            </span>
            <ChartContainer
              config={config}
              className="aspect-auto h-[280px] w-full"
            >
              <LineChart
                data={entries}
                margin={{ top: 8, right: 12, bottom: 0, left: 4 }}
              >
                {/* Horizontal hairlines only, and SOLID — a dashed grid reads
                    as a threshold or a projection when it is just a grid. */}
                <CartesianGrid vertical={false} />
                <XAxis
                  dataKey="date"
                  tickLine={false}
                  axisLine={false}
                  tickMargin={8}
                  minTickGap={28}
                  tickFormatter={(value: string) => formatBusinessDay(value)}
                />
                <YAxis
                  tickLine={false}
                  axisLine={false}
                  tickMargin={8}
                  width={52}
                  tickFormatter={(value: number) => compactMvr(value)}
                />
                <ChartTooltip
                  cursor={{ strokeWidth: 1 }}
                  content={
                    <ChartTooltipContent
                      labelFormatter={(value) =>
                        formatBusinessDay(String(value), 'long')
                      }
                      formatter={(value, name, item) => (
                        // A short stroke keys the series, not a filled box:
                        // at tooltip density a box is data-weight ink doing a
                        // label's job. The VALUE is the strong element — the
                        // reader already has the series and wants the number.
                        <div className="flex w-full items-center gap-2">
                          <span
                            className="h-0.5 w-3 shrink-0 rounded-full"
                            style={{ backgroundColor: item?.color }}
                          />
                          <span className="min-w-0 flex-1 text-muted-foreground">
                            {name}
                          </span>
                          <span className="font-medium text-foreground">
                            <Amount value={value} />
                          </span>
                        </div>
                      )}
                    />
                  }
                />
                {series.map((line) => (
                  <Line
                    key={line.key}
                    dataKey={line.key}
                    name={line.label}
                    // Linear, never smoothed: a curve through daily money
                    // draws values on days that never had them.
                    type="linear"
                    stroke={`var(--color-${line.key})`}
                    strokeWidth={2}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    // No dot per day — up to 366 of them is a field of noise.
                    // The hovered day gets one, ringed in the card colour so
                    // it stays legible where the two lines cross.
                    dot={false}
                    activeDot={{
                      r: 4,
                      strokeWidth: 2,
                      stroke: 'var(--color-card)',
                    }}
                    isAnimationActive={false}
                  />
                ))}
                {/* Always present for two or more series — identity is never
                    left to colour-matching alone. */}
                <ChartLegend content={<ChartLegendContent />} />
              </LineChart>
            </ChartContainer>
          </>
        )}
      </CardContent>
    </Card>
  );
}
