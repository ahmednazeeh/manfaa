'use client';

import { MoneyText } from '@manfaa/ui';
import {
  formatBpPercent,
  type FixturePreview,
  type TierBand,
} from '@/lib/fee-tiers';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

/**
 * A §4 tier table rendered the way the plan writes it: cashback range →
 * platform fee, with the merchant all-in column (cashback + fee) so the
 * 4.99% → 5.00% cliff is visible at a glance.
 *
 * The two basis-point columns are the ENGINE's unit, shown because an admin
 * reasoning about a one-hundredth-of-a-percent boundary needs the integer.
 * They are not wire fields — the API states every rate as a percent string
 * (PLAN §1) and this panel converts once, on the way in.
 */
export function FeeTierTable({ bands }: { bands: TierBand[] }) {
  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Customer cashback</TableHead>
            <TableHead className="text-end">Cashback (bp)</TableHead>
            <TableHead className="text-end">Platform fee</TableHead>
            <TableHead className="text-end">Fee (bp)</TableHead>
            <TableHead className="text-end">Merchant all-in</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {bands.map((band) => (
            <TableRow key={`${band.from_bp}-${band.to_bp}`}>
              <TableCell>
                {formatBpPercent(band.from_bp)} – {formatBpPercent(band.to_bp)}
              </TableCell>
              <TableCell className="text-end text-muted-foreground">
                {band.from_bp} – {band.to_bp}
              </TableCell>
              <TableCell className="text-end font-medium">
                {formatBpPercent(band.fee_bp)}
              </TableCell>
              <TableCell className="text-end text-muted-foreground">
                {band.fee_bp}
              </TableCell>
              <TableCell className="text-end">
                {formatBpPercent(band.from_bp + band.fee_bp)} –{' '}
                {formatBpPercent(band.to_bp + band.fee_bp)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/**
 * The §4 fixture (four invoices at 2.00% cashback) computed line by line
 * with ceiling rounding — round at the line, then sum. Under the shipped
 * tiers this is exactly the plan's 8,600 / 3,225 / 11,825 laari table.
 */
export function Section4PreviewTable({ preview }: { preview: FixturePreview }) {
  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Invoice</TableHead>
            <TableHead className="text-end">Eligible</TableHead>
            <TableHead className="text-end">
              Cashback @ {formatBpPercent(200)}
            </TableHead>
            <TableHead className="text-end">
              Fee @ {formatBpPercent(preview.fee_bp)}
            </TableHead>
            <TableHead className="text-end">Merchant due</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {preview.lines.map((line) => (
            <TableRow key={line.invoice}>
              <TableCell>{line.invoice}</TableCell>
              <TableCell className="text-end">
                <MoneyText laari={line.eligible_laari} />
              </TableCell>
              <TableCell className="text-end">
                <MoneyText laari={line.cashback_laari} />
              </TableCell>
              <TableCell className="text-end">
                <MoneyText laari={line.fee_laari} />
              </TableCell>
              <TableCell className="text-end">
                <MoneyText laari={line.due_laari} />
              </TableCell>
            </TableRow>
          ))}
          <TableRow className="font-semibold">
            <TableCell>Batch</TableCell>
            <TableCell className="text-end">
              <MoneyText laari={preview.totals.eligible_laari} />
            </TableCell>
            <TableCell className="text-end">
              <MoneyText laari={preview.totals.cashback_laari} />
            </TableCell>
            <TableCell className="text-end">
              <MoneyText laari={preview.totals.fee_laari} />
            </TableCell>
            <TableCell className="text-end">
              <MoneyText laari={preview.totals.due_laari} />
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  );
}
