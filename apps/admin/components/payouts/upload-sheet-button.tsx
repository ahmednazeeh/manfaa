'use client';

import { useRef } from 'react';
import { uploadAdminPayoutSheet } from '@manfaa/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Upload } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { Button } from '@/components/ui/button';

/**
 * Sends the exported transfer sheet back with the bank's reference numbers
 * filled in. Rows are matched on their Idempotency Key and a row whose
 * Transfer Reference Number is still blank is passed over, so the same sheet
 * can go up again each time the bank works a little further down it.
 *
 * The sheet has no failure column on purpose — a rejected transfer is
 * recorded on the row itself, where the reason has to be typed out.
 */
export function UploadSheetButton({ batchId }: { batchId: number }) {
  const inputRef = useRef<HTMLInputElement>(null);
  const queryClient = useQueryClient();

  const upload = useMutation({
    mutationFn: (file: File) => uploadAdminPayoutSheet(batchId, file),
    onSuccess: (response) => {
      queryClient.setQueryData(['admin', 'payout-batch', batchId], response);
      queryClient.invalidateQueries({ queryKey: ['admin', 'payout-batches'] });

      const items = response.data.items ?? [];
      const paid = items.filter((item) => item.state === 'paid').length;
      const waiting = items.filter(
        (item) => item.state === 'pending' || item.state === 'sent',
      ).length;

      toast.success(
        waiting === 0
          ? 'Sheet uploaded — nothing on this batch is still waiting.'
          : `Sheet uploaded — ${paid} of ${items.length} items paid, ${waiting} still waiting.`,
      );
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (file) {
            upload.mutate(file);
          }
          // Allow re-uploading the same filename after a failure.
          event.target.value = '';
        }}
      />
      <Button
        variant="outline"
        onClick={() => inputRef.current?.click()}
        disabled={upload.isPending}
      >
        <Upload />
        {upload.isPending ? 'Uploading…' : 'Upload filled sheet'}
      </Button>
    </>
  );
}
