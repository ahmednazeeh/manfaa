'use client';

import { BANKS, bankOf, type BankSlug } from '@manfaa/api-client';
import { cn } from '@/lib/utils';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

/**
 * Choosing a bank, and showing which one was chosen.
 *
 * Bank was a text box on every form until this landed, and a text box asks
 * someone to spell their bank's name the same way we happen to. Two banks
 * take money in this country; a picker with their marks on it is both faster
 * to use and impossible to typo.
 */

/**
 * A bank's mark at a fixed square, on a light plate.
 *
 * The plate is not decoration: both logos are supplied on white, so a bare
 * <img> in dark mode floats a white-on-white shape on a dark card. Rendering
 * them on their own ground keeps the brands legible in either theme and
 * keeps us from recolouring marks we do not own.
 *
 * An unrecognised bank — a row written before the picker existed — draws no
 * plate at all rather than a broken image.
 */
export function BankLogo({
  bank,
  className,
}: {
  bank: string | null | undefined;
  className?: string;
}) {
  const resolved = bankOf(bank);

  if (resolved === null) {
    return null;
  }

  return (
    <span
      className={cn(
        'inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-white p-1',
        className,
      )}
    >
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={resolved.logo}
        alt=""
        className="size-full object-contain"
        loading="lazy"
      />
    </span>
  );
}

/** The bank's mark beside its name — the read-only counterpart of the picker. */
export function BankLabel({
  bank,
  className,
}: {
  bank: string | null | undefined;
  className?: string;
}) {
  const resolved = bankOf(bank);

  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <BankLogo bank={bank} />
      {/* Falls back to whatever was stored, so a value this build does not
          know still reads as itself instead of vanishing. */}
      <span className="truncate">{resolved?.label ?? bank ?? ''}</span>
    </span>
  );
}

export function BankSelect({
  value,
  onChange,
  id,
  disabled,
  placeholder,
}: {
  value: BankSlug | '';
  onChange: (bank: BankSlug) => void;
  id?: string;
  disabled?: boolean;
  placeholder?: string;
}) {
  return (
    <Select
      value={value}
      onValueChange={(next) => onChange(next as BankSlug)}
      disabled={disabled}
    >
      <SelectTrigger id={id}>
        <SelectValue placeholder={placeholder ?? 'Choose a bank'} />
      </SelectTrigger>
      <SelectContent>
        {BANKS.map((bank) => (
          <SelectItem key={bank.slug} value={bank.slug}>
            <span className="inline-flex items-center gap-2.5">
              <BankLogo bank={bank.slug} className="size-6" />
              <span>{bank.label}</span>
            </span>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
