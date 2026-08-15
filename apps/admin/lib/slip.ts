import { adminSettlementSlipPath, apiFetchBlob } from '@manfaa/api-client';

/** A fetched slip held in memory. `url` is a blob: URL owned by the caller. */
export interface SlipObject {
  url: string;
  /** Mime reported by the response, i.e. the one derived from the bytes. */
  mime: string;
  isImage: boolean;
  isPdf: boolean;
}

/**
 * Fetches a merchant's uploaded receipt slip through the authorised admin
 * route and hands back an in-memory object URL.
 *
 * The slip disk is private — no URL, not served — so the bytes only ever
 * arrive over a credentialed request against the admin guard. Fetching rather
 * than pointing an <img> at the route is deliberate: a missing slip, a missing
 * file, or an expired session then surfaces as a real message instead of a
 * broken-image icon.
 *
 * The caller owns the returned url and MUST revoke it (URL.revokeObjectURL)
 * when the preview unmounts.
 */
export async function fetchSlipObject(
  settlementId: number,
  paymentId: number,
  signal?: AbortSignal,
): Promise<SlipObject> {
  const blob = await apiFetchBlob(
    adminSettlementSlipPath(settlementId, paymentId),
    {
      signal,
      // JSON first so Laravel answers failures as JSON, not an HTML error page.
      headers: { Accept: 'application/json, image/*, application/pdf' },
    },
  );

  const mime = blob.type === '' ? 'application/octet-stream' : blob.type;

  return {
    url: URL.createObjectURL(blob),
    mime,
    isImage: mime.startsWith('image/'),
    isPdf: mime === 'application/pdf',
  };
}

/** "412 KB" / "1.2 MB" for a stored slip size; em dash when unknown. */
export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) {
    return '—';
  }
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${Math.round(bytes / 1024)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
