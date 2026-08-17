import { ReactNode } from 'react';
import { Metadata } from 'next';
import { Inter } from 'next/font/google';
import { ThemeProvider } from 'next-themes';
import { cn } from '@/lib/utils';
import { BrandThemeApplier } from '@/components/app/brand-theme';
import { I18nProvider } from '@/providers/i18n-provider';
import { QueryProvider } from '@/providers/query-provider';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import '@/styles/globals.css';
// Thaana @font-face + `html[lang='dv'] body` font rule (self-hosted fonts).
import '@manfaa/ui/styles.css';

const inter = Inter({ subsets: ['latin'] });

export const metadata: Metadata = {
  title: {
    template: '%s | Manfaa',
    default: 'Manfaa', // a default is required when creating a template
  },
};

export default async function RootLayout({
  children,
}: {
  children: ReactNode;
}) {
  return (
    // Server-rendered defaults; I18nProvider swaps lang/dir to the persisted
    // choice after hydration (dv -> lang="dv" dir="rtl"), hence
    // suppressHydrationWarning (already required by next-themes).
    <html className="h-full" lang="en" dir="ltr" suppressHydrationWarning>
      <body
        className={cn(
          'antialiased flex h-full text-base text-foreground bg-background',
          inter.className,
        )}
      >
        <ThemeProvider
          attribute="class"
          defaultTheme="light"
          storageKey="nextjs-theme"
          enableSystem
          disableTransitionOnChange
          enableColorScheme
        >
          <I18nProvider>
            <QueryProvider>
              {/* No Suspense here: nothing in this app calls useSearchParams,
                  and a root-level boundary makes dynamic routes stream — the
                  200 commits before /store/[slug]'s notFound() can 404. */}
              <TooltipProvider delayDuration={0}>
                <BrandThemeApplier />
                {children}
                <Toaster />
              </TooltipProvider>
            </QueryProvider>
          </I18nProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}
