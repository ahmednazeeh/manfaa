import { ReactNode, Suspense } from 'react';
import { Metadata } from 'next';
import { Inter } from 'next/font/google';
import { ThemeProvider } from 'next-themes';
import { cn } from '@/lib/utils';
import { I18nProvider } from '@/providers/i18n-provider';
import { QueryProvider } from '@/providers/query-provider';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import '@/styles/globals.css';
// Thaana @font-face + `html[lang='dv'] body` font rule (self-hosted fonts).
import '@manfaa/ui/styles.css';

const inter = Inter({ subsets: ['latin'] });

export const metadata: Metadata = {
  // Served by the API, so a superadmin can replace it without a deploy.
  // The app/favicon.ico file convention is deliberately NOT used alongside
  // this: two declarations would be two sources for one mark.
  icons: { icon: '/api/brand/favicon' },
  title: {
    template: '%s | Manfaa Merchant',
    default: 'Manfaa Merchant', // a default is required when creating a template
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
              <TooltipProvider delayDuration={0}>
                <Suspense>{children}</Suspense>
                <Toaster />
              </TooltipProvider>
            </QueryProvider>
          </I18nProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}
