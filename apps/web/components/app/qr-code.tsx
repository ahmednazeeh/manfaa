'use client';

import { useMemo } from 'react';
import { encodeQr, QR_SIZE, qrPath } from '@/lib/qr';

const QUIET_ZONE = 4;

/**
 * Inline SVG QR for the customer code — no dependencies (lib/qr). Always
 * dark-on-white regardless of theme: till scanners need the contrast, so
 * the white backing is part of the code, not the page background.
 *
 * Renders nothing when the value exceeds the encoder's capacity; the caller
 * shows its fallback note in that case (see `encodeQr`).
 */
export function QrCode({
  value,
  label,
  className,
}: {
  value: string;
  /** Accessible name for the QR image. */
  label: string;
  className?: string;
}) {
  const modules = useMemo(() => encodeQr(value), [value]);

  if (modules === null) {
    return null;
  }

  const span = QR_SIZE + QUIET_ZONE * 2;

  return (
    <svg
      viewBox={`${-QUIET_ZONE} ${-QUIET_ZONE} ${span} ${span}`}
      role="img"
      aria-label={label}
      shapeRendering="crispEdges"
      className={className}
    >
      <rect
        x={-QUIET_ZONE}
        y={-QUIET_ZONE}
        width={span}
        height={span}
        fill="#ffffff"
      />
      <path d={qrPath(modules)} fill="#000000" />
    </svg>
  );
}
