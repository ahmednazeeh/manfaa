import type { MerchantChannel } from '@manfaa/api-client';
import { merchantChannelLabel } from '@/lib/labels';
import { Badge } from '@/components/ui/badge';

/**
 * Display copy for the store channel enum. NEVER the literal "both" — the
 * §1 decision spells it "In Store & Online". The words live in lib/labels.ts
 * with the rest of the console's machine-code vocabulary.
 */
export function ChannelBadge({ channel }: { channel: MerchantChannel }) {
  return (
    <Badge variant="secondary" appearance="light" size="sm">
      {merchantChannelLabel(channel)}
    </Badge>
  );
}
