'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { merchantOnboardingChecklist } from '@manfaa/api-client';
import { ChevronDown, Circle, CircleCheck, Route } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
  apiErrorMessage,
  useOnboardingGuide,
  useSkipOnboarding,
} from '@/lib/queries';
import { can } from '@/lib/roles';
import { cn } from '@/lib/utils';
import { useLanguage } from '@/providers/i18n-provider';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Progress } from '@/components/ui/progress';
import { useLayout } from '@/components/app-layout/context';
import { useTour } from './tour-provider';
import { TOURS } from './tours';

/**
 * THE GUIDED-SETUP TASKLIST (owner, 2026-08-25) — bottom-left, under the
 * navigation, for a merchant user's first five days.
 *
 * It is a GUEST in the sidebar and behaves like one:
 *
 *  - it draws only while the server says `show`, and it keeps no local
 *    "dismissed" flag beside that answer. Five days from this person's own
 *    first sign-in the server stops saying show, and this disappears
 *    whether or not anything was finished. Skipping is permanent, immediate
 *    and shared with the phone app;
 *  - the navigation is the primary thing, so the list caps its own height
 *    (max-h-[40vh], scrolling inside) and the menu above it takes the rest.
 *    On a short laptop the nav shrinks and scrolls; it never gets pushed off
 *    the bottom of the screen;
 *  - nothing here is tickable. Every ✓ is the server reporting real state —
 *    a transaction exists, a settlement exists, a second staff account
 *    exists — so the list cannot be lied to, by us or by the reader;
 *  - the rows are filtered to what this account may actually do. A cashier
 *    is never told to add the shop's bank account, and the count then
 *    describes the rows on screen rather than the store's whole list.
 */
export function OnboardingTasklist({ className }: { className?: string }) {
  const { t } = useTranslation();
  const { language } = useLanguage();
  const { me } = useLayout();
  const guide = useOnboardingGuide();
  const skip = useSkipOnboarding();
  const { startTour } = useTour();
  const [expanded, setExpanded] = useState(() => readExpanded());
  const [confirmingSkip, setConfirmingSkip] = useState(false);

  const dhivehi = language === 'dv';
  const data = guide.data;

  const checklist = useMemo(
    () =>
      data === undefined
        ? null
        : merchantOnboardingChecklist(data, me.permissions),
    [data, me.permissions],
  );

  // The tours this person could actually walk. Offering a cashier a tour of
  // settling would be four highlights of controls that are not on their
  // screen — and the tour would skip every one of them and end at once.
  const tours = useMemo(
    () => TOURS.filter((tour) => can(me, tour.permission)),
    [me],
  );

  if (data === undefined || checklist === null || !checklist.show) {
    return null;
  }

  const { done, total, tasks } = checklist;
  // The one thing to do next, and the only row whose instructions are worth
  // the space: five help sentences at once is a wall, not a guide.
  const upNext = tasks.find((task) => !task.done) ?? null;
  const daysLeft = Math.max(0, data.days_remaining);

  const toggle = (open: boolean) => {
    setExpanded(open);
    writeExpanded(open);
  };

  return (
    <>
      <div
        data-slot="onboarding-tasklist"
        className={cn(
          'shrink-0 border-t border-border bg-background px-5 py-3',
          className,
        )}
      >
        <Collapsible open={expanded} onOpenChange={toggle}>
          <CollapsibleTrigger asChild>
            <button
              type="button"
              className="flex w-full cursor-pointer items-center gap-2 text-start"
            >
              <Route className="size-4 shrink-0 text-primary" />
              <span className="grow truncate text-sm font-medium text-mono">
                {dhivehi ? data.title_dv : data.title_en}
              </span>
              <span className="shrink-0 text-xs font-medium tabular-nums text-muted-foreground">
                {t('onboarding.tasklist.progress', { done, total })}
              </span>
              <ChevronDown
                className={cn(
                  'size-4 shrink-0 text-muted-foreground transition-transform',
                  expanded && 'rotate-180',
                )}
              />
            </button>
          </CollapsibleTrigger>

          {/* Visible collapsed as well as open: the point of the five-day
              window is that it is running out, and a merchant who folded
              the list away should still see that. */}
          <div className="mt-2 flex items-center gap-2">
            <Progress
              value={total === 0 ? 0 : (done / total) * 100}
              className="h-1"
              aria-label={t('onboarding.tasklist.progress', { done, total })}
            />
            <span className="shrink-0 text-[11px] text-muted-foreground">
              {t('onboarding.tasklist.daysLeft', { count: daysLeft })}
            </span>
          </div>

          <CollapsibleContent>
            {/* The cap that keeps the navigation on screen. */}
            <div className="mt-2.5 max-h-[40vh] overflow-y-auto">
              <ul className="flex flex-col gap-px">
                {tasks.map((task) => (
                  <li key={task.key}>
                    <Link
                      href={task.web_path}
                      className={cn(
                        'flex items-start gap-2 rounded-md px-1.5 py-1.5 text-xs hover:bg-muted',
                        task.done
                          ? 'text-muted-foreground'
                          : 'text-accent-foreground',
                      )}
                    >
                      {task.done ? (
                        <CircleCheck className="mt-px size-3.5 shrink-0 text-[var(--color-success-accent,var(--color-green-500))]" />
                      ) : (
                        <Circle className="mt-px size-3.5 shrink-0 text-muted-foreground/60" />
                      )}
                      <span className={cn(task.done && 'line-through')}>
                        {dhivehi ? task.label_dv : task.label_en}
                      </span>
                    </Link>
                    {upNext !== null && upNext.key === task.key && (
                      <p className="mb-1 ps-7 pe-1.5 text-[11px] leading-relaxed text-muted-foreground">
                        {dhivehi ? task.help_dv : task.help_en}
                      </p>
                    )}
                  </li>
                ))}
              </ul>

              {tours.length > 0 && (
                <div className="mt-2 flex flex-col gap-1 border-t border-border pt-2">
                  <span className="px-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground/70">
                    {t('onboarding.tasklist.showMe')}
                  </span>
                  {tours.map((tour) => (
                    <button
                      key={tour.id}
                      type="button"
                      onClick={() => startTour(tour.id)}
                      className="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1.5 text-start text-xs text-primary hover:bg-muted"
                    >
                      <Route className="size-3.5 shrink-0" />
                      <span>{t(`${tour.i18nKey}.name`)}</span>
                    </button>
                  ))}
                </div>
              )}

              <div className="mt-2 border-t border-border pt-2">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-full justify-start px-1.5 text-[11px] text-muted-foreground"
                  onClick={() => setConfirmingSkip(true)}
                  disabled={skip.isPending}
                >
                  {t('onboarding.tasklist.skip')}
                </Button>
              </div>
            </div>
          </CollapsibleContent>
        </Collapsible>
      </div>

      {/* Skipping cannot be undone from anywhere — not here, not on the
          phone — so it is asked once, in words that say exactly that. */}
      <AlertDialog open={confirmingSkip} onOpenChange={setConfirmingSkip}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('onboarding.tasklist.skipConfirmTitle')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('onboarding.tasklist.skipConfirmBody')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>
              {t('onboarding.tasklist.skipKeep')}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                setConfirmingSkip(false);
                // A skip that did not reach the server is not a skip: the
                // list stays exactly where it was, so the reader is TOLD
                // rather than left to conclude the button is broken. The
                // till app says the same sentence for the same failure.
                skip.mutate(undefined, {
                  onError: (error) =>
                    toast.error(
                      apiErrorMessage(
                        error,
                        t('onboarding.tasklist.skipFailed'),
                      ),
                    ),
                });
              }}
            >
              {t('onboarding.tasklist.skipConfirm')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}

/**
 * Whether the list is folded open, remembered per browser.
 *
 * Local, and deliberately NOT where "skipped" lives: folding this away is a
 * preference about one screen, while skipping is an account-level decision
 * the server owns and the phone shares. Storing the second one here would
 * mean a merchant who skipped on their laptop met the list again on their
 * phone, and a cleared browser would resurrect a list they buried.
 */
const EXPANDED_STORAGE_KEY = 'manfaa-onboarding-expanded';

function readExpanded(): boolean {
  if (typeof window === 'undefined') {
    return true;
  }
  try {
    return window.localStorage.getItem(EXPANDED_STORAGE_KEY) !== 'false';
  } catch {
    // Private mode, or storage blocked entirely: open is the useful default.
    return true;
  }
}

function writeExpanded(expanded: boolean): void {
  try {
    window.localStorage.setItem(EXPANDED_STORAGE_KEY, String(expanded));
  } catch {
    // Nothing to do and nothing to tell anybody: it is a fold state.
  }
}
