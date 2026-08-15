'use client';

import Link from 'next/link';
import {
  type StoreBranch,
  type StoreCategoryRate,
  type StoreDetail,
} from '@manfaa/api-client';
import { useFormatMoney } from '@manfaa/ui';
import {
  ArrowLeft,
  BadgeCheck,
  Banknote,
  Globe,
  LoaderCircle,
  MapPin,
  QrCode,
  Sparkles,
  Store,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
  formatDate,
  formatMonthYear,
  formatRate,
  splitDistance,
} from '@/lib/format';
import { haversineMeters } from '@/lib/geo';
import { isNotFound, useMe, useStore } from '@/lib/queries';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorBlock, LoadingBlock } from '@/components/app/async-states';
import { useLocationRequest } from '@/components/app/discovery';
import { PublicFooter, PublicHeader } from '@/components/app/public-header';
import { StoreAvatar } from '@/components/app/store-avatar';
import {
  ChannelChip,
  useCategoryLabel,
  useStoreName,
} from '@/components/app/store-labels';

/**
 * PUBLIC store page (/store/[slug]): the merchant's cashback offer in full —
 * hero rate (promo-aware), how earning works (always future-conditional:
 * "you'll earn … once the store confirms", never a promise), the merchant's
 * own cashback-basis wording verbatim, and branches with tap-to-locate
 * distances. The server component (page.tsx) fetches the store and passes it
 * as `initialStore`, so the full offer is in the SSR HTML; this client keeps
 * the data fresh thereafter through the same react-query path. A 404 is
 * identical for unknown and unlisted merchants, so the not-found view stays
 * friendly and unspecific.
 */

export function StoreNotFound() {
  const { t } = useTranslation();

  return (
    <div className="flex grow items-center justify-center py-16">
      <Card className="w-full max-w-md">
        <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <Store className="size-5" />
          </span>
          <div className="flex flex-col gap-1.5">
            <h1 className="text-lg font-semibold text-mono">
              {t('store.notFoundTitle')}
            </h1>
            <p className="text-sm text-muted-foreground">
              {t('store.notFoundBody')}
            </p>
          </div>
          <Button asChild>
            <Link href="/discover">{t('store.browseStores')}</Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}

/** The big rate block; promo-aware ("5% — usually 2%" + ends + minimum). */
function CashbackHero({ store }: { store: StoreDetail }) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const promo = store.promotion;

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 p-6">
        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <span className="text-4xl font-bold tracking-tight text-primary">
            {formatRate(store.cashback_rate_percent)}
          </span>
          <span className="text-base text-muted-foreground">
            {t('discover.cashbackLabel')}
          </span>
          {promo !== null && (
            <span className="text-sm text-muted-foreground line-through">
              {t('discover.usuallyRate', {
                rate: formatRate(store.standing_cashback_rate_percent),
              })}
            </span>
          )}
        </div>

        {promo !== null && (
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="warning" appearance="light" size="sm">
              {t('discover.endsChip', { date: formatDate(promo.ends_at) })}
            </Badge>
            {promo.min_purchase_laari !== null && (
              <span className="text-xs text-muted-foreground">
                {t('store.minPurchase', {
                  amount: formatMoney(promo.min_purchase_laari),
                })}
              </span>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function EarnStep({
  icon: Icon,
  step,
  title,
  body,
}: {
  icon: LucideIcon;
  step: number;
  title: string;
  body: string;
}) {
  return (
    <li className="flex items-start gap-3">
      <span className="relative mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
        <Icon className="size-4.5" />
        <span className="absolute -top-1 -end-1 flex size-4.5 items-center justify-center rounded-full bg-primary text-[0.625rem] font-semibold text-primary-foreground">
          {step}
        </span>
      </span>
      <div className="flex flex-col gap-0.5">
        <h3 className="text-sm font-semibold text-mono">{title}</h3>
        <p className="text-sm text-muted-foreground">{body}</p>
      </div>
    </li>
  );
}

/**
 * How earning works AT THIS STORE. Step 1 adapts to the store's channel
 * (till, online, or both); the final step keeps the §9.4 discipline — the
 * copy is future-conditional on the store confirming, never a promise.
 */
function HowToEarn({ store }: { store: StoreDetail }) {
  const { t } = useTranslation();

  const step1 =
    store.channel === 'online'
      ? {
          icon: Globe,
          title: t('store.stepShowOnlineTitle'),
          body: t('store.stepShowOnlineBody'),
        }
      : store.channel === 'both'
        ? {
            icon: QrCode,
            title: t('store.stepShowBothTitle'),
            body: t('store.stepShowBothBody'),
          }
        : {
            icon: QrCode,
            title: t('store.stepShowTillTitle'),
            body: t('store.stepShowTillBody'),
          };

  return (
    <section className="flex flex-col gap-4">
      <h2 className="text-base font-semibold text-mono">
        {t('store.howToEarnTitle')}
      </h2>
      <ol className="flex flex-col gap-4">
        <EarnStep
          icon={step1.icon}
          step={1}
          title={step1.title}
          body={step1.body}
        />
        <EarnStep
          icon={BadgeCheck}
          step={2}
          title={t('store.stepConfirmTitle')}
          body={t('store.stepConfirmBody')}
        />
        <EarnStep
          icon={Banknote}
          step={3}
          title={t('store.stepPaidTitle')}
          body={t('store.stepPaidBody', {
            rate: formatRate(store.cashback_rate_percent),
          })}
        />
      </ol>
    </section>
  );
}

/**
 * The store's own product categories as a rate table (Task #25). Names are
 * merchant data, not locale keys: Dhivehi UI shows `name_dv` when the
 * merchant provided one, else the English name (same rule as the merchant
 * panel). Excluded categories show a localised "Excluded" — they earn
 * nothing, even during promotions — and the closing row states what
 * everything NOT listed earns: the standing rate. `dir="auto"` lets a
 * Thaana name render correctly inside an English page and vice versa.
 */
function CategoryRatesTable({
  rows,
  standingRatePercent,
}: {
  rows: StoreCategoryRate[];
  standingRatePercent: string;
}) {
  const { t, i18n } = useTranslation();

  const displayName = (row: StoreCategoryRate): string =>
    i18n.language === 'dv' && row.name_dv !== null && row.name_dv !== ''
      ? row.name_dv
      : row.name_en;

  return (
    <div className="flex flex-col gap-2 border-t border-border pt-4">
      <h3 className="text-sm font-semibold text-mono">
        {t('store.categoryRatesTitle')}
      </h3>
      <ul className="flex flex-col text-sm">
        {rows.map((row, index) => (
          <li
            key={index}
            className="flex items-center justify-between gap-3 py-1.5"
          >
            <span dir="auto" className="text-muted-foreground">
              {displayName(row)}
            </span>
            {row.mode === 'excluded' ? (
              <span className="font-medium text-muted-foreground">
                {t('store.categoryExcluded')}
              </span>
            ) : (
              <span className="font-medium text-mono">
                {/* cashback_rate_percent is non-null whenever mode is
                    "rate"; the standing rate is the schema-level fallback
                    (unlisted ⇒ standing). */}
                {formatRate(row.cashback_rate_percent ?? standingRatePercent)}
              </span>
            )}
          </li>
        ))}
        <li className="flex items-center justify-between gap-3 border-t border-border py-1.5 mt-0.5 pt-2">
          <span className="text-muted-foreground">
            {t('store.categoryEverythingElse')}
          </span>
          <span className="font-medium text-mono">
            {formatRate(standingRatePercent)}
          </span>
        </li>
      </ul>
    </div>
  );
}

/** Rate breakdown + the per-category rate table (when the store defines
 *  product categories) + the merchant's own terms wording (verbatim, or a
 *  neutral fallback line when the merchant has set none). */
function CashbackDetails({ store }: { store: StoreDetail }) {
  const { t } = useTranslation();
  const formatMoney = useFormatMoney();
  const promo = store.promotion;

  return (
    <section className="flex flex-col gap-4">
      <h2 className="text-base font-semibold text-mono">
        {t('store.detailsTitle')}
      </h2>
      <Card>
        <CardContent className="flex flex-col gap-4 p-6">
          <dl className="flex flex-col gap-2 text-sm">
            <div className="flex items-center justify-between gap-3">
              <dt className="text-muted-foreground">
                {t('store.standingRateLabel')}
              </dt>
              <dd className="font-medium text-mono">
                {formatRate(store.standing_cashback_rate_percent)}
              </dd>
            </div>
            {promo !== null && (
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">
                  {t('store.promoRateLabel', {
                    date: formatDate(promo.ends_at),
                  })}
                </dt>
                <dd className="font-medium text-primary">
                  {formatRate(promo.cashback_rate_percent)}
                </dd>
              </div>
            )}
            {promo !== null && promo.min_purchase_laari !== null && (
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted-foreground">
                  {t('store.minPurchaseLabel')}
                </dt>
                <dd className="font-medium text-mono">
                  {formatMoney(promo.min_purchase_laari)}
                </dd>
              </div>
            )}
          </dl>

          {store.category_rates.length > 0 && (
            <CategoryRatesTable
              rows={store.category_rates}
              standingRatePercent={store.standing_cashback_rate_percent}
            />
          )}

          <div className="flex flex-col gap-1.5 border-t border-border pt-4">
            <h3 className="text-sm font-semibold text-mono">
              {t('store.termsTitle')}
            </h3>
            <p className="text-sm text-muted-foreground">
              {store.cashback_basis ?? t('store.termsFallback')}
            </p>
          </div>
        </CardContent>
      </Card>
    </section>
  );
}

function BranchRow({
  branch,
  coords,
}: {
  branch: StoreBranch;
  coords: { lat: number; lng: number } | null;
}) {
  const { t } = useTranslation();

  const meters =
    coords !== null && branch.lat !== null && branch.lng !== null
      ? haversineMeters(coords.lat, coords.lng, branch.lat, branch.lng)
      : null;
  const distance = meters === null ? null : splitDistance(meters);

  return (
    <li className="flex items-start justify-between gap-3 py-3">
      <div className="flex flex-col gap-0.5">
        <span className="text-sm font-medium text-mono">{branch.name}</span>
        {branch.address !== null && (
          <span className="text-sm text-muted-foreground">
            {branch.address}
          </span>
        )}
      </div>
      {distance !== null && (
        <span className="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
          <MapPin className="size-3" />
          {distance.unit === 'm'
            ? t('discover.distanceMeters', { meters: distance.value })
            : t('discover.distanceKm', { km: distance.value })}
        </span>
      )}
    </li>
  );
}

/** Branch list; distances appear only after the explicit location gesture. */
function Branches({ branches }: { branches: StoreBranch[] }) {
  const { geo, coords, requestLocation } = useLocationRequest();
  const { t } = useTranslation();

  if (branches.length === 0) {
    return null;
  }

  const locatable = branches.some(
    (branch) => branch.lat !== null && branch.lng !== null,
  );

  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-semibold text-mono">
          {t('store.branchesTitle')}
        </h2>
        {locatable && geo.kind !== 'granted' && (
          <Button
            variant="outline"
            size="sm"
            onClick={requestLocation}
            disabled={geo.kind === 'locating'}
          >
            {geo.kind === 'locating' ? (
              <>
                <LoaderCircle className="animate-spin" />
                {t('discover.locating')}
              </>
            ) : (
              <>
                <MapPin />
                {t('discover.useMyLocation')}
              </>
            )}
          </Button>
        )}
      </div>

      {(geo.kind === 'denied' || geo.kind === 'unavailable') && (
        <p className="text-xs text-muted-foreground">
          {geo.kind === 'denied'
            ? t('store.locationDenied')
            : t('store.locationUnavailable')}
        </p>
      )}

      <Card>
        <CardContent className="px-6 py-2">
          <ul className="divide-y divide-border">
            {branches.map((branch, index) => (
              <BranchRow key={index} branch={branch} coords={coords} />
            ))}
          </ul>
        </CardContent>
      </Card>
    </section>
  );
}

/** Join/Sign-in CTA for visitors; a Dashboard pointer once signed in. */
function StoreCta({ store }: { store: StoreDetail }) {
  const { t } = useTranslation();
  const storeName = useStoreName();
  const { data: me } = useMe();

  return (
    <Card className="bg-muted/40">
      <CardContent className="flex flex-col items-center gap-4 p-6 text-center">
        <p className="max-w-md text-sm text-muted-foreground">
          {me
            ? t('store.ctaSignedInBody', { name: storeName(store) })
            : t('store.ctaBody', {
                rate: formatRate(store.cashback_rate_percent),
                name: storeName(store),
              })}
        </p>
        {me ? (
          <Button asChild>
            <Link href="/dashboard">{t('landing.dashboard')}</Link>
          </Button>
        ) : (
          <div className="flex flex-wrap items-center justify-center gap-2">
            <Button asChild>
              <Link href="/signup">{t('landing.createAccount')}</Link>
            </Button>
            <Button variant="outline" asChild>
              <Link href="/login">{t('auth.signIn')}</Link>
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function StoreContent({ store }: { store: StoreDetail }) {
  const { t } = useTranslation();
  const categoryLabel = useCategoryLabel();
  const storeName = useStoreName();

  return (
    <div className="flex w-full max-w-3xl flex-col gap-8 pb-10">
      <div className="flex items-center gap-4">
        <StoreAvatar
          name={storeName(store)}
          slug={store.slug}
          logoUrl={store.logo_url}
          size="lg"
        />
        <div className="flex min-w-0 flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-semibold tracking-tight text-mono">
              {storeName(store)}
            </h1>
            {store.featured && (
              <Badge variant="primary" appearance="light" size="sm">
                <Sparkles className="size-3" />
                {t('store.featuredBadge')}
              </Badge>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
            {store.category !== null && (
              <span>{categoryLabel(store.category)}</span>
            )}
            {/* The channel label sits right beside the category — always
                the localised label, never the raw enum. */}
            <ChannelChip channel={store.channel} />
            {store.joined !== null && (
              <span>
                {t('store.joinedLabel', {
                  date: formatMonthYear(store.joined),
                })}
              </span>
            )}
          </div>
        </div>
      </div>

      <CashbackHero store={store} />
      <HowToEarn store={store} />
      <CashbackDetails store={store} />
      <Branches branches={store.branches} />
      <StoreCta store={store} />
    </div>
  );
}

export default function StorePageClient({
  slug,
  initialStore,
}: {
  slug: string;
  /**
   * SSR-fetched store, when the server could reach the API. Undefined only
   * on upstream trouble — the client query then fetches (and retries) on its
   * own, exactly as this page behaved before it grew a server component.
   */
  initialStore?: StoreDetail;
}) {
  const { data: store, isPending, error } = useStore(slug, initialStore);
  const { t } = useTranslation();

  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <PublicHeader />

      <main className="container flex grow flex-col py-8 lg:py-10">
        <div className="pb-6">
          <Link
            href="/discover"
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="size-4 rtl:rotate-180" />
            {t('store.backToDiscover')}
          </Link>
        </div>

        {isPending && <LoadingBlock lines={6} />}
        {!isPending && error && isNotFound(error) && <StoreNotFound />}
        {!isPending && error && !isNotFound(error) && (
          <ErrorBlock error={error} />
        )}
        {store && <StoreContent store={store} />}
      </main>

      <PublicFooter />
    </div>
  );
}
