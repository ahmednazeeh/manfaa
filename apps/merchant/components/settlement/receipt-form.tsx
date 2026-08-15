'use client';

import {
  useEffect,
  useRef,
  useState,
  type DragEvent,
  type ReactNode,
} from 'react';
import { parseMvrToLaari } from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import {
  FileText,
  LoaderCircle,
  Paperclip,
  TriangleAlert,
  Upload,
  X,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
  checkSlipFile,
  SLIP_ACCEPT_ATTRIBUTE,
  type ReceiptSubmission,
} from '@/lib/api';
import {
  apiErrorCode,
  apiErrorMessage,
  isSelectionRefusal,
} from '@/lib/queries';
import { cn } from '@/lib/utils';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input, InputAddon, InputGroup } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * The receipt half of the receipt-first flow (PLAN §1): the slip, the bank
 * reference and the amount actually transferred. Used twice — as the wizard's
 * final step (where submitting CREATES the settlement) and on a settlement
 * that is still owed money (§7 partial payments, and admin-built fallback
 * batches), where it adds a further receipt.
 *
 * The file is checked here for size and type as a courtesy — the server
 * decides by magic bytes and refuses a renamed SVG regardless (SlipStorage).
 * The amount is parsed with parseMvrToLaari (string decomposition), so no
 * money value passes through a float.
 */

/** Integer laari → a plain "1234.56" for the amount input. */
function laariToInput(laari: number): string {
  const sign = laari < 0 ? '-' : '';
  const abs = Math.abs(laari);
  return `${sign}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

function safeParseMvr(input: string): number | null {
  try {
    return parseMvrToLaari(input);
  } catch {
    return null;
  }
}

function formatBytes(bytes: number): string {
  return bytes >= 1024 * 1024
    ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function ReceiptForm({
  amountDueLaari,
  submitLabel,
  pending,
  error,
  onSubmit,
  footerStart,
}: {
  /** Prefills the amount and drives the under/over-payment notes. */
  amountDueLaari: number;
  submitLabel: string;
  pending: boolean;
  /** The submission error, if the last attempt failed. */
  error: unknown;
  onSubmit: (receipt: ReceiptSubmission) => void;
  /** Rendered at the start of the action row (e.g. a Back button). */
  footerStart?: ReactNode;
}) {
  const { t } = useTranslation();
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [dragging, setDragging] = useState(false);
  const [bankRef, setBankRef] = useState('');
  const [amountInput, setAmountInput] = useState(() =>
    laariToInput(amountDueLaari),
  );
  const [touched, setTouched] = useState(false);

  // One object URL per chosen file, revoked the moment it is replaced or the
  // form unmounts — a preview must not leak the blob it points at.
  useEffect(() => {
    if (file === null) {
      setPreviewUrl(null);
      return;
    }
    const url = URL.createObjectURL(file);
    setPreviewUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);

  const accept = (candidate: File | undefined | null) => {
    if (!candidate) return;
    const rejection = checkSlipFile(candidate);
    if (rejection !== null) {
      setFile(null);
      setFileError(
        rejection === 'too_large'
          ? t('settlement.fileTooLarge')
          : t('settlement.fileUnsupported'),
      );
      return;
    }
    setFileError(null);
    setFile(candidate);
  };

  const onDrop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setDragging(false);
    accept(event.dataTransfer.files?.[0]);
  };

  const amountLaari = safeParseMvr(amountInput);
  const amountInvalid = amountLaari === null || amountLaari < 1;
  const bankRefTrimmed = bankRef.trim();
  const isPdf = file?.type === 'application/pdf';
  const canSubmit =
    file !== null && !amountInvalid && bankRefTrimmed !== '' && !pending;

  const submit = () => {
    setTouched(true);
    if (!canSubmit || file === null || amountLaari === null) return;
    onSubmit({ amountLaari, bankRef: bankRefTrimmed, slip: file });
  };

  const duplicateRef = apiErrorCode(error) === 'duplicate_bank_ref';
  const slipRefused = apiErrorCode(error)?.startsWith('slip_') === true;
  const selectionRefused = isSelectionRefusal(error);

  return (
    <div className="flex flex-col gap-5">
      <div
        onDragOver={(event) => {
          event.preventDefault();
          setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={onDrop}
        className={cn(
          'rounded-lg border border-dashed p-6 text-center transition-colors',
          dragging ? 'border-primary bg-primary/5' : 'border-border',
        )}
      >
        <input
          ref={inputRef}
          type="file"
          accept={SLIP_ACCEPT_ATTRIBUTE}
          className="hidden"
          onChange={(event) => {
            accept(event.target.files?.[0]);
            // Let the same file be re-picked after a rejection.
            event.target.value = '';
          }}
        />

        {file === null ? (
          <div className="flex flex-col items-center gap-2">
            <Upload className="size-6 text-muted-foreground" />
            <button
              type="button"
              className="text-sm font-medium text-primary cursor-pointer"
              onClick={() => inputRef.current?.click()}
            >
              {t('settlement.dropzone')}
            </button>
            <span className="text-xs text-muted-foreground">
              {t('settlement.dropzoneHint')}
            </span>
          </div>
        ) : (
          <div className="flex flex-col items-center gap-3">
            {isPdf ? (
              <object
                data={previewUrl ?? undefined}
                type="application/pdf"
                className="h-56 w-full rounded-md border border-border"
                aria-label={t('settlement.previewAlt')}
              >
                <div className="flex h-full items-center justify-center gap-2 text-sm text-muted-foreground">
                  <FileText className="size-4" />
                  {t('settlement.pdfChosen')}
                </div>
              </object>
            ) : (
              previewUrl !== null && (
                // A local blob URL: next/image would proxy it pointlessly.
                <img
                  src={previewUrl}
                  alt={t('settlement.previewAlt')}
                  className="max-h-56 rounded-md border border-border object-contain"
                />
              )
            )}
            <div className="flex flex-wrap items-center justify-center gap-2 text-xs text-muted-foreground">
              <Paperclip className="size-3.5" />
              <span className="max-w-64 truncate" dir="ltr">
                {file.name}
              </span>
              <span>· {formatBytes(file.size)}</span>
            </div>
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => inputRef.current?.click()}
              >
                {t('settlement.replaceFile')}
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  setFile(null);
                  setFileError(null);
                }}
              >
                <X />
                {t('settlement.removeFile')}
              </Button>
            </div>
          </div>
        )}
      </div>

      {fileError !== null && (
        <p className="text-xs text-destructive">{fileError}</p>
      )}
      {fileError === null && touched && file === null && (
        <p className="text-xs text-destructive">
          {t('settlement.fileRequired')}
        </p>
      )}

      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div className="flex flex-col gap-2.5">
          <Label htmlFor="bank-ref">{t('settlement.bankRefLabel')}</Label>
          <Input
            id="bank-ref"
            value={bankRef}
            maxLength={128}
            dir="ltr"
            onChange={(event) => setBankRef(event.target.value)}
            aria-invalid={touched && bankRefTrimmed === ''}
          />
          {touched && bankRefTrimmed === '' ? (
            <p className="text-xs text-destructive">
              {t('settlement.bankRefRequired')}
            </p>
          ) : (
            <p className="text-xs text-muted-foreground">
              {t('settlement.bankRefHint')}
            </p>
          )}
        </div>

        <div className="flex flex-col gap-2.5">
          <Label htmlFor="transferred-amount">
            {t('settlement.amountLabel')}
          </Label>
          <InputGroup>
            <InputAddon>MVR</InputAddon>
            <Input
              id="transferred-amount"
              inputMode="decimal"
              dir="ltr"
              value={amountInput}
              onChange={(event) => setAmountInput(event.target.value)}
              aria-invalid={amountInvalid && touched}
            />
          </InputGroup>
          {amountInvalid ? (
            <p className="text-xs text-destructive">
              {t('settlement.amountInvalid')}
            </p>
          ) : (
            <p className="text-xs text-muted-foreground">
              {t('settlement.amountHint')}
            </p>
          )}
        </div>
      </div>

      {!amountInvalid &&
        amountLaari !== null &&
        amountLaari < amountDueLaari && (
          <Alert variant="warning" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertTitle>{t('settlement.amountUnder')}</AlertTitle>
          </Alert>
        )}
      {!amountInvalid &&
        amountLaari !== null &&
        amountLaari > amountDueLaari && (
          <Alert variant="info" appearance="light">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertTitle>{t('settlement.amountOver')}</AlertTitle>
          </Alert>
        )}

      {error !== null && error !== undefined && (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>
              {duplicateRef
                ? t('settlement.duplicateBankRef')
                : slipRefused
                  ? apiErrorMessage(error, t('settlement.fileUnsupported'))
                  : selectionRefused
                    ? t('settlement.notEligible')
                    : apiErrorMessage(error, t('settlement.submitFailed'))}
            </AlertTitle>
            {slipRefused && (
              <AlertDescription>
                {t('settlement.dropzoneHint')}
              </AlertDescription>
            )}
          </AlertContent>
        </Alert>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          {footerStart}
          <span className="text-sm text-muted-foreground">
            {t('settlement.amountToTransfer')}:{' '}
            <MoneyText
              laari={amountDueLaari}
              className="font-medium text-mono"
            />
          </span>
        </div>
        <Button onClick={submit} disabled={pending}>
          {pending ? <LoaderCircle className="animate-spin" /> : <Upload />}
          {submitLabel}
        </Button>
      </div>
    </div>
  );
}
