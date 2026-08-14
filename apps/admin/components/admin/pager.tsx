'use client';

import type { PaginationMeta } from '@manfaa/api-client';
import { Button } from '@/components/ui/button';

/** Minimal prev/next pager driven by Laravel's pagination meta. */
export function Pager({
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
    <div className="flex items-center justify-between gap-4 pt-4">
      <span className="text-sm text-muted-foreground">
        {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
      </span>
      {meta.last_page > 1 ? (
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page <= 1}
            onClick={() => onPageChange(meta.current_page - 1)}
          >
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">
            Page {meta.current_page} of {meta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => onPageChange(meta.current_page + 1)}
          >
            Next
          </Button>
        </div>
      ) : null}
    </div>
  );
}
