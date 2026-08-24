'use client';

import { type ReactNode } from 'react';
import {
  formatBpPercent,
  REPORT_PREVIEW_ROWS,
  type ReportCell,
  type ReportColumnType,
  type ReportPreviewSheet,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { formatDateTime } from '@/lib/format';
import { formatCount } from '@/lib/reports';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTable, CardTitle } from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

/**
 * The head of the report's primary sheet, rendered from the column meta the
 * API sends with it.
 *
 * Rows arrive POSITIONAL — `row[i]` belongs to `columns[i]` — and are read
 * that way here, never by key. A finance table where column six is sometimes
 * GST and sometimes the fee is worse than no table at all, and the column
 * order is the only thing that says which is which.
 */

const DASH = <span className="text-muted-foreground">—</span>;

/** Right-aligned types: everything that is a figure rather than a word. */
const NUMERIC: ReadonlySet<ReportColumnType> = new Set<ReportColumnType>([
  'int',
  'money',
  'percent',
]);

/**
 * One cell, rendered by what its column MEANS. The wire carries bare scalars,
 * so nothing else distinguishes 2000 laari from 2000 basis points — the type
 * is the whole of the instruction.
 */
function cellNode(cell: ReportCell, type: ReportColumnType): ReactNode {
  if (type === 'money') {
    return typeof cell === 'number' ? <MoneyText laari={cell} /> : DASH;
  }

  if (type === 'percent') {
    return typeof cell === 'number' ? formatBpPercent(cell) : DASH;
  }

  if (type === 'int') {
    return typeof cell === 'number' ? formatCount(cell) : DASH;
  }

  if (type === 'date') {
    return typeof cell === 'string' && cell !== ''
      ? formatDateTime(cell)
      : DASH;
  }

  return typeof cell === 'string' && cell !== '' ? cell : DASH;
}

export function ReportPreviewTable({
  preview,
  rowCount,
  capped,
}: {
  preview: ReportPreviewSheet;
  /** Rows the primary sheet holds in full — the preview may show fewer. */
  rowCount: number;
  capped: boolean;
}) {
  const { columns, rows } = preview;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2.5">
          {preview.sheet}
          <Badge variant="secondary" appearance="light" size="sm">
            {formatCount(rowCount)} {rowCount === 1 ? 'row' : 'rows'}
          </Badge>
        </CardTitle>
        {capped ? (
          <span className="text-xs text-muted-foreground">
            Showing the first {formatCount(REPORT_PREVIEW_ROWS)} — export the
            .xlsx for all {formatCount(rowCount)}.
          </span>
        ) : null}
      </CardHeader>
      <CardTable>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                {columns.map((column) => (
                  <TableHead
                    key={column.key}
                    className={cn(
                      'whitespace-nowrap',
                      NUMERIC.has(column.type) && 'text-end',
                    )}
                  >
                    {column.label}
                  </TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={Math.max(columns.length, 1)}
                    className="py-10 text-center text-muted-foreground"
                  >
                    Nothing in this period.
                  </TableCell>
                </TableRow>
              ) : (
                rows.map((row, rowIndex) => (
                  <TableRow key={rowIndex}>
                    {columns.map((column, columnIndex) => (
                      <TableCell
                        key={column.key}
                        className={cn(
                          'whitespace-nowrap',
                          NUMERIC.has(column.type) && 'text-end tabular-nums',
                        )}
                      >
                        {cellNode(row[columnIndex] ?? null, column.type)}
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>
      </CardTable>
    </Card>
  );
}
