'use client';

import { useRef } from 'react';
import { importAdminPayoutResults } from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Upload } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';

/**
 * Uploads the bank's result CSV. The refreshed batch comes back with every
 * item's final state — paid or failed with a reason — which the items table
 * renders as the per-item result.
 */
export function ImportResultsButton({ batchId }: { batchId: number }) {
  const inputRef = useRef<HTMLInputElement>(null);
  const queryClient = useQueryClient();

  const importResults = useMutation({
    mutationFn: (file: File) => importAdminPayoutResults(batchId, file),
    onSuccess: (response) => {
      queryClient.setQueryData(['admin', 'payout-batch', batchId], response);
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });

      const items = response.data.items ?? [];
      const failed = items.filter((item) => item.state === 'failed').length;
      const paid = items.filter((item) => item.state === 'paid').length;

      if (failed > 0) {
        toast.warning(
          `Results imported: ${paid} paid, ${failed} failed — failed items are flagged below.`,
        );
      } else {
        toast.success(`Results imported: ${paid} items paid.`);
      }
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept=".csv,text/csv"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (file) {
            importResults.mutate(file);
          }
          // Allow re-uploading the same filename after a failure.
          event.target.value = '';
        }}
      />
      <Button
        variant="outline"
        onClick={() => inputRef.current?.click()}
        disabled={importResults.isPending}
      >
        <Upload />
        {importResults.isPending ? 'Importing…' : 'Import results CSV'}
      </Button>
    </>
  );
}
