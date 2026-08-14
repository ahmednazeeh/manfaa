import type { MerchantChannel } from '@manfaa/api-client';
import { Badge } from '@/components/ui/badge';

/**
 * Display copy for the store channel enum. NEVER the literal "both" — the
 * §1 decision spells it "In Store & Online".
 */
export const CHANNEL_LABELS: Record<MerchantChannel, string> = {
  in_store: 'In Store',
  online: 'Online',
  both: 'In Store & Online',
};

export function ChannelBadge({ channel }: { channel: MerchantChannel }) {
  return (
    <Badge variant="secondary" appearance="light" size="sm">
      {CHANNEL_LABELS[channel]}
    </Badge>
  );
}
