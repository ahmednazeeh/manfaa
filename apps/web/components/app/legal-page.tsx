import { ReactNode } from 'react';
import { PublicFooter, PublicHeader } from '@/components/app/public-header';

/**
 * Shared shell for the legal estate (/privacy, /terms, /account-deletion).
 * The body is deliberately English-only and pinned LTR: legal text is a
 * single authoritative wording, so it does not flip with the site locale —
 * a Dhivehi translation, when it lands, becomes its own reviewed document
 * rather than a UI string swap.
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
  return (
    <div className="flex min-h-screen flex-col bg-background">
      <PublicHeader />
      <main className="container grow py-10 lg:py-14">
        <article dir="ltr" lang="en" className="mx-auto w-full max-w-3xl">
          <h1 className="text-3xl font-semibold text-mono">{title}</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Last updated: {updated}
          </p>
          <div className="mt-8 flex flex-col gap-8">{children}</div>
        </article>
      </main>
      <PublicFooter />
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
