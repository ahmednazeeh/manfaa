'use client';

import { getAdminPlatformSettings } from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';

/**
 * Whether the marketplace is switched on (Settings › Platform).
 *
 * The server already refuses `/admin/marketplace/*` and
 * `/admin/merchant-settlements` when it is off — those routes carry
 * EnsureMarketplaceEnabled — so without this the panel offered menu items
 * that could only ever answer 403. This hides them instead.
 *
 * Undefined while loading, and treated as ON by the caller: a nav that
 * flickers items away on every page load is worse than one that briefly
 * shows a link the server would refuse anyway.
 */
export function useMarketplaceEnabled(): boolean | undefined {
  const query = useQuery({
    queryKey: ['admin', 'platform-settings', 'marketplace_enabled'],
    queryFn: ({ signal }) => getAdminPlatformSettings({ signal }),
    // The flag is a launch switch, not a live value — re-reading it on every
    // navigation would be four requests a minute for something that changes
    // once.
    staleTime: 5 * 60 * 1000,
    // Number(), not `=== 1`: the value is an integer on the wire today, but
    // this platform has already been bitten once by a numeric arriving as a
    // string through the Redis cache (see PlatformConfig::get's int cast).
    // Getting this wrong hides working menus silently, which is the kind of
    // bug nobody reports — they just assume the screen is gone.
    select: (response) => Number(response.data.marketplace_enabled?.value) === 1,
  });

  return query.data;
}
