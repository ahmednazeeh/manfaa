'use client';

import { useEffect, useState } from 'react';
import type { SettlementPayment } from '@manfaa/api-client';
import { ExternalLink, FileText, ImageOff, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { fetchSlipObject, formatBytes, type SlipObject } from '@/lib/slip';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The merchant's uploaded transfer slip, streamed from the authorised admin
 * route (the private disk has no public path, signed or otherwise). Images
 * render inline; a PDF gets an open-in-new-tab link, which is all a browser
 * needs to display it, plus an inline frame where the browser supports one.
 */
export function SlipPreview({
  settlementId,
  payment,
}: {
  settlementId: number;
  payment: SettlementPayment;
}) {
  const [slip, setSlip] = useState<SlipObject | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!payment.has_slip) {
      return;
    }

    const controller = new AbortController();
    let objectUrl: string | null = null;

    setSlip(null);
    setError(null);

    fetchSlipObject(settlementId, payment.id, controller.signal)
      .then((fetched) => {
        objectUrl = fetched.url;
        setSlip(fetched);
      })
      .catch((cause: unknown) => {
        if (controller.signal.aborted) {
          return;
        }
        setError(apiErrorMessage(cause, 'The slip could not be loaded.'));
      });

    return () => {
      controller.abort();
      if (objectUrl !== null) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [settlementId, payment.id, payment.has_slip]);

  if (!payment.has_slip) {
    return (
      <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-muted/40 px-6 py-10 text-center">
        <ImageOff className="size-6 text-muted-foreground" />
        <p className="text-sm font-medium">No slip on this payment</p>
        <p className="text-xs text-muted-foreground">
          Payments recorded from a bank statement carry no upload. Verify the
          bank reference against the statement before matching.
        </p>
      </div>
    );
  }

  if (error !== null) {
    return (
      <Alert variant="destructive" appearance="light">
        <AlertIcon>
          <TriangleAlert />
        </AlertIcon>
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }

  if (slip === null) {
    return <Skeleton className="h-72 w-full" />;
  }

  const meta = `${slip.mime} · ${formatBytes(payment.slip_size_bytes)}`;

  if (slip.isImage) {
    return (
      <figure className="flex flex-col gap-2">
        <a
          href={slip.url}
          target="_blank"
          rel="noreferrer"
          className="block overflow-hidden rounded-lg border border-border bg-muted/40"
          title="Open the slip full size in a new tab"
        >
          <img
            src={slip.url}
            alt={`Transfer slip for bank reference ${payment.bank_ref ?? 'unknown'}`}
            className="max-h-[28rem] w-full object-contain"
          />
        </a>
        <figcaption className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
          <span>{meta}</span>
          <a
            href={slip.url}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
          >
            Open full size
            <ExternalLink className="size-3" />
          </a>
        </figcaption>
      </figure>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      {slip.isPdf ? (
        <object
          data={slip.url}
          type="application/pdf"
          className="h-[28rem] w-full rounded-lg border border-border bg-muted/40"
          aria-label={`Transfer slip PDF for bank reference ${payment.bank_ref ?? 'unknown'}`}
        >
          <div className="flex h-full flex-col items-center justify-center gap-2 p-6 text-center">
            <FileText className="size-6 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">
              This browser cannot display the PDF inline.
            </p>
          </div>
        </object>
      ) : (
        <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-border bg-muted/40 px-6 py-10 text-center">
          <FileText className="size-6 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">
            The slip is not an image or a PDF — open it to review it.
          </p>
        </div>
      )}
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <span>{meta}</span>
        <Button variant="outline" size="sm" asChild>
          <a href={slip.url} target="_blank" rel="noreferrer">
            <ExternalLink />
            {slip.isPdf ? 'Open PDF' : 'Open slip'}
          </a>
        </Button>
      </div>
    </div>
  );
}
