'use client';

import { Check, Copy } from 'lucide-react';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import { Button } from '@/components/ui/button';

/**
 * Copies one payment-instruction value (bank name, account number, account
 * name, reference) and flashes a check. Every instance owns its own copied
 * state, so the tick appears on the button that was actually pressed.
 */
export function CopyButton({ value, label }: { value: string; label: string }) {
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      mode="icon"
      aria-label={label}
      title={label}
      onClick={() => copyToClipboard(value)}
    >
      {isCopied ? <Check className="text-green-500" /> : <Copy />}
    </Button>
  );
}
