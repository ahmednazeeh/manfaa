'use client';

import { useMemo, useState } from 'react';
import { type Customer } from '@manfaa/api-client';
import { Check, Copy, Maximize2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { encodeQr } from '@/lib/qr';
import { cn } from '@/lib/utils';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { QrCode } from '@/components/app/qr-code';

/**
 * The Manfaa code card — the thing a customer actually opens at a till, so
 * it earns its place near the top of the dashboard (first thing after the
 * balance on phones). The QR is big enough to scan from the card itself;
 * "Show full screen" blows it up for a scanner that struggles, and the
 * copy action covers tills that take the number by keyboard.
 */

/** The number itself, spaced and pinned LTR (digits inside Dhivehi text). */
function CodeDigits({ code, className }: { code: string; className?: string }) {
  return (
    <span
      // -me cancels the trailing letter-space, which would otherwise widen
      // the box past the glyphs and leave the centred code off to one side.
      className={cn(
        '-me-[0.2em] font-semibold tracking-[0.2em] text-mono tabular-nums',
        className,
      )}
      dir="ltr"
    >
      {code}
    </span>
  );
}

export function CodeCard({
  me,
  labelClassName,
  className,
}: {
  me: Customer;
  /** The dashboard's shared muted-uppercase label style. */
  labelClassName: string;
  className?: string;
}) {
  const { t } = useTranslation();
  const [fullScreen, setFullScreen] = useState(false);
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  const qrAvailable = useMemo(
    () => encodeQr(me.customer_code) !== null,
    [me.customer_code],
  );

  return (
    <Card className={className}>
      <CardContent className="flex flex-col items-center gap-4 p-6 text-center">
        <span className={cn('self-start text-start', labelClassName)}>
          {t('dashboard.yourCode')}
        </span>

        {qrAvailable && (
          <div className="overflow-hidden rounded-xl border border-border">
            <QrCode
              value={me.customer_code}
              label={t('dashboard.qrAlt', { code: me.customer_code })}
              className="size-36"
            />
          </div>
        )}

        <CodeDigits code={me.customer_code} className="text-4xl" />

        <span className="text-xs text-muted-foreground">
          {qrAvailable ? t('dashboard.codeHint') : t('dashboard.qrUnavailable')}
        </span>

        <div className="flex flex-wrap items-center justify-center gap-2">
          {qrAvailable && (
            <Button
              size="sm"
              variant="outline"
              onClick={() => setFullScreen(true)}
            >
              <Maximize2 />
              {t('dashboard.showFullScreen')}
            </Button>
          )}
          <Button
            size="sm"
            variant="outline"
            onClick={() => copyToClipboard(me.customer_code)}
          >
            {isCopied ? <Check className="text-brand" /> : <Copy />}
            {isCopied ? t('dashboard.codeCopied') : t('dashboard.copyCode')}
          </Button>
        </div>
      </CardContent>

      {qrAvailable && (
        <Dialog open={fullScreen} onOpenChange={setFullScreen}>
          <DialogContent className="max-w-sm">
            <DialogHeader className="mb-0">
              <DialogTitle>{t('dashboard.yourCode')}</DialogTitle>
              <DialogDescription>{t('dashboard.codeHint')}</DialogDescription>
            </DialogHeader>
            <div className="flex flex-col items-center gap-4 py-2">
              <div className="w-full max-w-72 overflow-hidden rounded-xl border border-border">
                <QrCode
                  value={me.customer_code}
                  label={t('dashboard.qrAlt', { code: me.customer_code })}
                  className="aspect-square w-full"
                />
              </div>
              <CodeDigits code={me.customer_code} className="text-3xl" />
            </div>
          </DialogContent>
        </Dialog>
      )}
    </Card>
  );
}
