'use client';

import { forwardRef } from 'react';
import { cn } from '@/lib/utils';
import { Input } from '@/components/ui/input';

/**
 * A Maldivian mobile field: a fixed +960, then the seven digits people
 * actually say.
 *
 * Nobody here recites their country code, so asking for one made the field
 * a small quiz about formatting — and the answers ("+960 771 2345", "960
 * 7712345", "07712345") all had to be untangled anyway. Showing the prefix
 * as furniture rather than as something to type removes the question.
 *
 * The wrapper is pinned dir="ltr" so the prefix stays on the reading-left in
 * the Dhivehi layout: a phone number is a left-to-right run of digits
 * wherever it appears, and the bidi algorithm would otherwise reorder it.
 * Padding uses the logical `ps-` so the affix and the text never collide.
 *
 * The value is the seven digits; every caller already passes them through
 * normalizeMaldivesPhone, which accepts exactly that and returns the E.164
 * the API stores.
 */
export const PhoneInput = forwardRef<
  HTMLInputElement,
  Omit<React.ComponentProps<typeof Input>, 'type' | 'dir'>
>(function PhoneInput({ className, onChange, ...props }, ref) {
  return (
    <div className="relative" dir="ltr">
      <span
        aria-hidden="true"
        className="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-sm text-muted-foreground"
      >
        +960
      </span>
      <Input
        {...props}
        ref={ref}
        type="tel"
        inputMode="numeric"
        autoComplete="tel-national"
        dir="ltr"
        maxLength={7}
        className={cn('ps-14', className)}
        onChange={(event) => {
          // Digits only. A paste of "+960 771 2345" would otherwise sit in a
          // seven-character field as "+960 77" — the field silently eating
          // the number rather than accepting it.
          const digits = event.target.value.replace(/\D/g, '').slice(-7);
          event.target.value = digits;
          onChange?.(event);
        }}
      />
    </div>
  );
});
