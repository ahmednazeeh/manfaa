'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { Camera, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

/**
 * Scans the customer's dashboard QR at the counter (PLAN §12 / Task #23).
 *
 * The customer app encodes the RAW 6-digit customer code in its QR (see
 * apps/web lib/qr.ts — a Version 1 byte-mode payload of the code itself), so
 * that is exactly what this accepts: anything else is reported as "not a
 * Manfaa code" rather than half-parsed out of an arbitrary payload.
 *
 * The whole feature is progressive enhancement over the BarcodeDetector API
 * (Chromium/Android; absent on Safari and Firefox). When the API or a camera
 * is missing the button does not render at all, and typing the code by hand
 * — which is always available — remains the only path. The camera stream is
 * stopped on close, on unmount and on every failure, so no track is left
 * live behind a closed dialog.
 */

interface DetectedBarcode {
  rawValue: string;
}

interface BarcodeDetectorInstance {
  detect(source: CanvasImageSource): Promise<DetectedBarcode[]>;
}

interface BarcodeDetectorConstructor {
  new (options?: { formats?: string[] }): BarcodeDetectorInstance;
  getSupportedFormats?: () => Promise<string[]>;
}

declare global {
  interface Window {
    BarcodeDetector?: BarcodeDetectorConstructor;
  }
}

/** Milliseconds between detection attempts — fast enough to feel instant. */
const SCAN_INTERVAL_MS = 250;

type ScanState = 'starting' | 'scanning' | 'denied' | 'no_camera' | 'failed';

/**
 * True only when BOTH halves exist: the Barcode Detection API (with QR among
 * its formats) and a camera we may ask for. Resolved in an effect, so the
 * server render and the first client render agree (no button, then it
 * appears if supported).
 */
export function useQrScanSupport(): boolean {
  const [supported, setSupported] = useState(false);

  useEffect(() => {
    const constructor = window.BarcodeDetector;
    const media = navigator.mediaDevices;

    if (constructor === undefined || media?.getUserMedia === undefined) {
      return;
    }

    let cancelled = false;

    const formats =
      typeof constructor.getSupportedFormats === 'function'
        ? constructor.getSupportedFormats()
        : Promise.resolve(['qr_code']);

    void formats
      .then((supportedFormats) => {
        if (!cancelled) {
          setSupported(supportedFormats.includes('qr_code'));
        }
      })
      .catch(() => {
        // A detector that cannot enumerate its formats is not one we rely on.
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return supported;
}

/** The customer QR carries the bare 6-digit code — nothing else counts. */
function readCustomerCode(rawValue: string): string | null {
  const trimmed = rawValue.trim();
  return /^\d{6}$/.test(trimmed) ? trimmed : null;
}

function stopStream(stream: MediaStream | null): void {
  stream?.getTracks().forEach((track) => track.stop());
}

export function QrScanButton({
  onCode,
  disabled = false,
}: {
  /** Called with a valid 6-digit code; the dialog closes itself first. */
  onCode: (code: string) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const supported = useQrScanSupport();
  const [open, setOpen] = useState(false);

  if (!supported) {
    return null;
  }

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={disabled}
        onClick={() => setOpen(true)}
      >
        <Camera />
        {t('credit.scanQr')}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t('credit.scanTitle')}</DialogTitle>
            <DialogDescription>{t('credit.scanBody')}</DialogDescription>
          </DialogHeader>
          <DialogBody>
            {open && (
              <ScannerViewport
                onCode={(code) => {
                  setOpen(false);
                  onCode(code);
                }}
              />
            )}
          </DialogBody>
        </DialogContent>
      </Dialog>
    </>
  );
}

/**
 * Mounted only while the dialog is open: acquiring the camera on mount and
 * releasing it on unmount is what guarantees the track dies with the dialog,
 * however it was closed (button, overlay click or Escape).
 */
function ScannerViewport({ onCode }: { onCode: (code: string) => void }) {
  const { t } = useTranslation();
  const videoRef = useRef<HTMLVideoElement>(null);
  const [state, setState] = useState<ScanState>('starting');
  const [unreadable, setUnreadable] = useState(false);

  // The callback identity must not restart the camera mid-scan.
  const onCodeRef = useRef(onCode);
  useEffect(() => {
    onCodeRef.current = onCode;
  }, [onCode]);

  const classify = useCallback((error: unknown): ScanState => {
    const name =
      typeof error === 'object' && error !== null && 'name' in error
        ? String((error as { name: unknown }).name)
        : '';
    if (name === 'NotAllowedError' || name === 'SecurityError') return 'denied';
    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
      return 'no_camera';
    }
    return 'failed';
  }, []);

  useEffect(() => {
    const constructor = window.BarcodeDetector;
    if (constructor === undefined) {
      setState('failed');
      return;
    }

    let stream: MediaStream | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;
    // Captured for the cleanup: by then videoRef.current may already be null,
    // and the element still holds the stream we have to detach.
    const videoElement = videoRef.current;

    const detector = new constructor({ formats: ['qr_code'] });

    const scan = async () => {
      const video = videoRef.current;
      if (stopped || video === null || video.readyState < 2) {
        timer = setTimeout(() => void scan(), SCAN_INTERVAL_MS);
        return;
      }

      try {
        const barcodes = await detector.detect(video);
        if (stopped) return;

        for (const barcode of barcodes) {
          const code = readCustomerCode(barcode.rawValue);
          if (code !== null) {
            stopped = true;
            onCodeRef.current(code);
            return;
          }
        }
        // Something was in frame but it was not a Manfaa code — say so and
        // keep looking, so the cashier can simply hold up the right screen.
        setUnreadable(barcodes.length > 0);
      } catch {
        // A single failed frame is not a failed scan; try the next one.
      }

      timer = setTimeout(() => void scan(), SCAN_INTERVAL_MS);
    };

    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: 'environment' } })
      .then(async (media) => {
        if (stopped) {
          stopStream(media);
          return;
        }
        stream = media;
        const video = videoRef.current;
        if (video === null) return;
        video.srcObject = media;
        try {
          await video.play();
        } catch {
          // Autoplay refusals still leave a usable preview on most browsers.
        }
        setState('scanning');
        void scan();
      })
      .catch((error: unknown) => {
        if (!stopped) setState(classify(error));
      });

    return () => {
      stopped = true;
      if (timer !== null) clearTimeout(timer);
      stopStream(stream);
      if (videoElement !== null) {
        videoElement.srcObject = null;
      }
    };
  }, [classify]);

  const failure =
    state === 'denied'
      ? t('credit.scanDenied')
      : state === 'no_camera'
        ? t('credit.scanNoCamera')
        : state === 'failed'
          ? t('credit.scanFailed')
          : null;

  return (
    <div className="flex flex-col gap-3">
      {failure !== null ? (
        <div className="flex items-start gap-2 rounded-md border border-border bg-muted/40 p-4 text-sm text-secondary-foreground">
          <TriangleAlert className="mt-0.5 size-4 shrink-0 text-yellow-500" />
          {failure}
        </div>
      ) : (
        <div className="relative overflow-hidden rounded-lg bg-black">
          <video
            ref={videoRef}
            className="aspect-square w-full object-cover"
            muted
            playsInline
            autoPlay
          />
          {state === 'starting' && (
            <div className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-white">
              <LoaderCircle className="size-4 animate-spin" />
              {t('credit.scanStarting')}
            </div>
          )}
        </div>
      )}

      {unreadable && failure === null && (
        <p className="text-xs text-yellow-600">{t('credit.scanUnreadable')}</p>
      )}
      <p className="text-xs text-muted-foreground">
        {t('credit.scanManualHint')}
      </p>
    </div>
  );
}
