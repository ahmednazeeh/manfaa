'use client';

import { type ReportHeaderBlock } from '@manfaa/api-client';
import { ArrowDownLeft, ArrowUpRight, Info } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';

/**
 * The workbook's own header block, on screen — the same title, the same
 * provenance facts and the same glossary the .xlsx opens with.
 *
 * It is here so the reader sees what the file will SAY before they download
 * it, and because the sentences are worth reading on screen too. Manfaa has
 * two opposite money flows and one blurry word between them:
 *
 *   MERCHANT SETTLEMENT   money IN  — the merchant pays Manfaa
 *   CUSTOMER PAYOUT       money OUT — Manfaa pays the customer
 *
 * "Settlement" alone never says which side is settling, and the person who
 * has to reconcile these figures months from now was not in the room when
 * the vocabulary was chosen.
 *
 * Every sentence comes from the API verbatim. Nothing here is composed in the
 * browser, so the screen and the workbook cannot drift into saying two
 * different things about the same rows — which on a reversed-rows setting
 * that changes every total would be worse than saying nothing.
 */

/** Direction-marked so the two flows read differently at a glance. */
function noteIcon(note: string) {
  if (note.startsWith('MERCHANT SETTLEMENT')) {
    return <ArrowDownLeft className="mt-0.5 size-3.5 shrink-0 text-primary" />;
  }
  if (note.startsWith('CUSTOMER PAYOUT')) {
    return <ArrowUpRight className="mt-0.5 size-3.5 shrink-0 text-primary" />;
  }
  return <Info className="mt-0.5 size-3.5 shrink-0 text-muted-foreground/70" />;
}

export function ReportHeaderBlockCard({
  header,
  includeReversed,
  reversedRowsApply,
}: {
  header: ReportHeaderBlock;
  /** What the server actually built, not what the switch currently says. */
  includeReversed: boolean;
  /**
   * Whether the setting can change this report at all. False on payouts and
   * on earnings, where the badge would otherwise announce an inclusion that
   * changed nothing — beside a fact line, from the same server, explaining
   * why it could not.
   */
  reversedRowsApply: boolean;
}) {
  const badge = !reversedRowsApply
    ? { variant: 'secondary' as const, text: 'Reversed rows not applicable' }
    : includeReversed
      ? { variant: 'warning' as const, text: 'Reversed rows included' }
      : { variant: 'secondary' as const, text: 'Reversed rows excluded' };

  return (
    <Card className="mb-4">
      <CardContent className="flex flex-col gap-3 py-4">
        <div className="flex flex-wrap items-center gap-2.5">
          <span className="text-sm font-medium text-foreground">
            {header.title}
          </span>
          <Badge variant={badge.variant} appearance="light" size="sm">
            {badge.text}
          </Badge>
        </div>

        {header.facts.length > 0 ? (
          <div className="flex flex-wrap gap-x-6 gap-y-1.5 text-xs">
            {header.facts.map((fact) => (
              <div key={fact.label} className="flex items-baseline gap-1.5">
                <span className="text-muted-foreground">{fact.label}</span>
                <span className="font-medium text-foreground">
                  {fact.value}
                </span>
              </div>
            ))}
          </div>
        ) : null}

        {header.notes.length > 0 ? (
          <div className="flex flex-col gap-1.5 border-t border-border pt-3">
            {header.notes.map((note) => (
              <div
                key={note}
                className="flex items-start gap-2 text-xs leading-relaxed text-muted-foreground"
              >
                {noteIcon(note)}
                <span>{note}</span>
              </div>
            ))}
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
