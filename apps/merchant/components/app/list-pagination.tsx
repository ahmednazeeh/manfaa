'use client';

import { type PaginationMeta } from '@manfaa/api-client';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

/** Prev/next pager over Laravel's pagination meta. */
export function ListPagination({
  meta,
  onPageChange,
}: {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
}) {
  if (meta.total === 0) {
    return null;
  }

  return (
    <div className="flex items-center justify-between gap-3 grow">
      <span className="text-sm text-muted-foreground">
        Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
      </span>
      <div className="flex items-center gap-1.5">
        <Button
          variant="outline"
          size="sm"
          mode="icon"
          aria-label="Previous page"
          disabled={meta.current_page <= 1}
          onClick={() => onPageChange(meta.current_page - 1)}
        >
          <ChevronLeft className="rtl:rotate-180" />
        </Button>
        <span className="text-sm text-secondary-foreground tabular-nums">
          {meta.current_page} / {meta.last_page}
        </span>
        <Button
          variant="outline"
          size="sm"
          mode="icon"
          aria-label="Next page"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPageChange(meta.current_page + 1)}
        >
          <ChevronRight className="rtl:rotate-180" />
        </Button>
      </div>
    </div>
  );
}
