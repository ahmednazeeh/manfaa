'use client';

import Image from 'next/image';
import Link from 'next/link';
import { ArrowUpRight, Download, KeyRound, Mail, Plug } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { INTEGRATION_GUIDE_URL, INTEGRATIONS_EMAIL } from '@/lib/integration';
import { RoleGate } from '@/components/app/role-gate';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';

const WOOCOMMERCE_PLUGIN_URL =
  'https://manfaa.app/app/woocommerce/manfaa-cashback.zip';
const ISLEBOOKS_CONNECT_URL = 'https://app.islebooks.mv/integrations/manfaa';
const WOO_GUIDE_URL = `${INTEGRATION_GUIDE_URL}#the-woocommerce-plugin`;

/**
 * The integrations hub (owner, 2026-08-23): one place that names every way
 * a store connects Manfaa to other software. The cards are doors — API &
 * Webhooks opens the existing self-serve screen; IsleBooks and WooCommerce
 * point at where that work actually happens (IsleBooks' own panel, the
 * plugin download), because Manfaa does not host either.
 */
export default function IntegrationsPage() {
  const { t } = useTranslation();

  return (
    <RoleGate permission="api_credentials.view">
      <div className="container">
        <Toolbar>
          <ToolbarHeading>
            <ToolbarPageTitle>{t('integrations.title')}</ToolbarPageTitle>
            <ToolbarDescription>{t('integrations.subtitle')}</ToolbarDescription>
          </ToolbarHeading>
        </Toolbar>

        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {/* API & Webhooks — the one that lives inside this panel. */}
          <IntegrationCard
            icon={
              <span className="flex size-12 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <KeyRound className="size-6" />
              </span>
            }
            title={t('integrations.apiTitle')}
            body={t('integrations.apiBody')}
          >
            <Button asChild>
              <Link href="/settings/api-access">
                <Plug />
                {t('integrations.apiCta')}
              </Link>
            </Button>
          </IntegrationCard>

          {/* IsleBooks — connected from inside IsleBooks, not here. */}
          <IntegrationCard
            icon={
              <Image
                src="/media/integrations/islebooks.png"
                alt="IsleBooks"
                width={48}
                height={48}
                className="size-12 rounded-lg object-contain"
              />
            }
            title={t('integrations.isleTitle')}
            body={t('integrations.isleBody')}
            note={t('integrations.isleSteps')}
          >
            <Button variant="outline" asChild>
              <a href={ISLEBOOKS_CONNECT_URL} target="_blank" rel="noreferrer noopener">
                {t('integrations.isleCta')}
                <ArrowUpRight />
              </a>
            </Button>
          </IntegrationCard>

          {/* WooCommerce — download the plugin, connect from WordPress. */}
          <IntegrationCard
            icon={
              <Image
                src="/media/integrations/woocommerce.png"
                alt="WooCommerce"
                width={48}
                height={48}
                className="size-12 rounded-lg object-contain"
              />
            }
            title={t('integrations.wooTitle')}
            body={t('integrations.wooBody')}
            note={t('integrations.wooMeta')}
          >
            <Button asChild>
              <a href={WOOCOMMERCE_PLUGIN_URL} download>
                <Download />
                {t('integrations.wooCta')}
              </a>
            </Button>
            <Button variant="outline" asChild>
              <a href={WOO_GUIDE_URL} target="_blank" rel="noreferrer noopener">
                {t('integrations.wooGuide')}
                <ArrowUpRight />
              </a>
            </Button>
          </IntegrationCard>

          {/* Anything else. */}
          <IntegrationCard
            icon={
              <span className="flex size-12 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Plug className="size-6" />
              </span>
            }
            title={t('integrations.moreTitle')}
            body={t('integrations.moreBody')}
          >
            <Button variant="outline" asChild>
              <a href={`mailto:${INTEGRATIONS_EMAIL}`}>
                <Mail />
                {t('integrations.moreCta')}
              </a>
            </Button>
          </IntegrationCard>
        </div>
      </div>
    </RoleGate>
  );
}

function IntegrationCard({
  icon,
  title,
  body,
  note,
  children,
}: {
  icon: React.ReactNode;
  title: string;
  body: string;
  note?: string;
  children: React.ReactNode;
}) {
  return (
    <Card className="flex flex-col">
      <CardContent className="flex grow flex-col gap-3">
        <div className="flex items-center gap-3">
          {icon}
          <h3 className="text-lg font-semibold">{title}</h3>
        </div>
        <p className="text-sm text-muted-foreground">{body}</p>
        {note ? (
          <p className="mt-auto rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
            {note}
          </p>
        ) : null}
      </CardContent>
      <CardFooter className="flex flex-wrap gap-2.5">{children}</CardFooter>
    </Card>
  );
}
