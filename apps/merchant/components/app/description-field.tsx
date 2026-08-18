'use client';

import {
  countWords,
  isOverWordCeiling,
  STORE_DESCRIPTION_MAX_WORDS,
} from '@manfaa/api-client';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * The store's own words about itself — one field, two screens (the setup
 * wizard's profile step and Settings → Profile), so it lives here rather
 * than being written twice with two different ceilings.
 *
 * The ceiling is WORDS, and the count comes from @manfaa/api-client, which
 * mirrors the API's App\Rules\MaxWords. That is the whole point of the
 * counter: it must go red on exactly the text a save would be refused for,
 * never a word earlier or later. There is deliberately no `maxLength` on the
 * textarea — a hard stop mid-sentence would be a character cap by the back
 * door, and the owner asked for a word ceiling precisely so that a Dhivehi
 * description is not refused for being written in long words.
 */

/** True once the text is past the ceiling — i.e. once a save would 422. */
export function descriptionTooLong(value: string): boolean {
  return isOverWordCeiling(value, STORE_DESCRIPTION_MAX_WORDS);
}

export function DescriptionField({
  id,
  value,
  onChange,
  error = null,
  className,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  /** The "you have not written one yet" refusal; the ceiling speaks for itself. */
  error?: string | null;
  className?: string;
}) {
  const { t } = useTranslation();

  const words = countWords(value);
  const over = words > STORE_DESCRIPTION_MAX_WORDS;
  const invalid = over || error !== null;

  return (
    <div className={cn('flex flex-col gap-2.5', className)}>
      <Label htmlFor={id}>{t('setup.descriptionLabel')}</Label>
      <Textarea
        id={id}
        rows={5}
        value={value}
        placeholder={t('setup.descriptionPlaceholder')}
        aria-invalid={invalid}
        aria-describedby={`${id}-count`}
        onChange={(event) => onChange(event.target.value)}
      />
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p
          className={cn(
            'min-w-0 flex-1 text-xs',
            error !== null ? 'text-destructive' : 'text-muted-foreground',
          )}
        >
          {error ?? t('setup.descriptionGuidance')}
        </p>
        {/* Latin digits in both languages — the MVR convention this panel
            keeps everywhere numbers appear. */}
        {/* Described-by rather than a live region: a count that announced
            itself every time a word landed would talk over the typing. */}
        <p
          id={`${id}-count`}
          dir="ltr"
          className={cn(
            'shrink-0 text-xs tabular-nums',
            over ? 'font-medium text-destructive' : 'text-muted-foreground',
          )}
        >
          {t('setup.descriptionCount', {
            words,
            max: STORE_DESCRIPTION_MAX_WORDS,
          })}
        </p>
      </div>
      {over && (
        <p className="text-xs text-destructive">
          {t('setup.descriptionTooLong', { max: STORE_DESCRIPTION_MAX_WORDS })}
        </p>
      )}
    </div>
  );
}
