'use client';

import { type PaginationMeta } from '@manfaa/api-client';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

/** Prev/next pager over Laravel's pagination meta. */
export function ListPagination({
  meta,
  onPageChange,
}: {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
}) {
  const { t } = useTranslation();

  if (meta.total === 0 || meta.last_page <= 1) {
    return null;
  }

  return (
    <div className="flex items-center justify-between gap-3 grow">
      <span className="text-sm text-muted-foreground">
        {t('common.showingRange', {
          from: meta.from ?? 0,
          to: meta.to ?? 0,
          total: meta.total,
        })}
      </span>
      <div className="flex items-center gap-1.5">
        <Button
          variant="outline"
          size="sm"
          mode="icon"
          aria-label={t('common.previousPage')}
          disabled={meta.current_page <= 1}
          onClick={() => onPageChange(meta.current_page - 1)}
        >
          <ChevronLeft className="rtl:rotate-180" />
        </Button>
        <span className="text-sm text-secondary-foreground tabular-nums">
          {t('common.pageOf', {
            page: meta.current_page,
            pages: meta.last_page,
          })}
        </span>
        <Button
          variant="outline"
          size="sm"
          mode="icon"
          aria-label={t('common.nextPage')}
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPageChange(meta.current_page + 1)}
        >
          <ChevronRight className="rtl:rotate-180" />
        </Button>
      </div>
    </div>
  );
}
