'use client';

import Link from 'next/link';
import { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { toAbsoluteUrl } from '@/lib/helpers';
import { Button } from '@/components/ui/button';

/**
 * Shell for the merchant panel's legal estate (/privacy, /terms,
 * /account-deletion): the landing's wordmark header over an English-only,
 * LTR-pinned article — legal text is one authoritative wording and does
 * not flip with the site locale.
 */
export function LegalPage({
  title,
  updated,
  children,
}: {
  title: string;
  updated: string;
  children: ReactNode;
}) {
  const { t } = useTranslation();

  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <header className="mx-auto flex w-full max-w-6xl items-center justify-between gap-3 px-5 py-4 sm:px-8">
        <Link href="/" className="flex items-center gap-2">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={toAbsoluteUrl('/media/app/mini-logo.svg?v=mf2')}
            className="h-5"
            alt=""
            aria-hidden
          />
          <span className="text-lg font-semibold tracking-tight text-mono">
            {t('common.appName')}
          </span>
          <span className="text-lg font-semibold text-violet-600 dark:text-violet-400">
            {t('marketing.wordmark')}
          </span>
        </Link>
        <Button asChild variant="outline">
          <Link href="/login">{t('marketing.login')}</Link>
        </Button>
      </header>

      <main className="grow py-10 lg:py-14">
        <article
          dir="ltr"
          lang="en"
          className="mx-auto w-full max-w-3xl px-5 sm:px-8"
        >
          <h1 className="text-3xl font-semibold text-mono">{title}</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Last updated: {updated}
          </p>
          <div className="mt-8 flex flex-col gap-8">{children}</div>
        </article>
      </main>

      <footer className="border-t border-border">
        <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-2 px-5 py-5 text-xs text-muted-foreground sm:px-8">
          <span>© {new Date().getFullYear()} Manfaa</span>
          <div className="flex items-center gap-4">
            <Link href="/privacy" className="hover:text-foreground">
              Privacy
            </Link>
            <Link href="/terms" className="hover:text-foreground">
              Terms
            </Link>
          </div>
        </div>
      </footer>
    </div>
  );
}

export function LegalSection({
  heading,
  children,
}: {
  heading: string;
  children: ReactNode;
}) {
  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold text-mono">{heading}</h2>
      <div className="flex flex-col gap-3 text-sm/relaxed text-muted-foreground [&_strong]:font-medium [&_strong]:text-foreground [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-5">
        {children}
      </div>
    </section>
  );
}
