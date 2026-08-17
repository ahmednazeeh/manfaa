'use client';

import { ReactNode } from 'react';
import { can } from '@/lib/roles';
import { useLayout } from '@/components/app-layout/context';
import {
  Toolbar,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import { RoleGateNotice } from '@/components/app/role-gate';
import { PreferencesSection } from '../preferences/page';
import { ProductCategoriesSection } from '../product-categories/page';
import { RateSection } from '../rate/page';

/**
 * Cashback settings — the merge of three formerly separate screens (owner,
 * 2026-08-17): the standing rate, the per-category rates and exclusions,
 * and the earning rules (validation window, minimum eligible amount). One
 * screen for everything that decides what a sale earns.
 *
 * Each section stands on its own permission, exactly as the standalone
 * screens did — an account holding only `preferences.update` sees only the
 * rules section. Holding none of the three renders the refusal, naming the
 * primary act. The settings layout leaves this path ungated on purpose
 * (menu.ts: any-of items self-gate).
 */
export default function CashbackSettingsPage() {
  const { me } = useLayout();

  const showRate = can(me, 'rate.view');
  const showCategories = can(me, 'product_categories.view');
  const showPreferences = can(me, 'preferences.update');

  if (!showRate && !showCategories && !showPreferences) {
    return (
      <div className="container">
        <RoleGateNotice permission="rate.view" />
      </div>
    );
  }

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Cashback settings</ToolbarPageTitle>
          <ToolbarDescription>
            Your rate, category rules, and how sales qualify
          </ToolbarDescription>
        </ToolbarHeading>
      </Toolbar>

      <div className="flex flex-col gap-10 pb-7.5">
        {showRate && (
          <Section
            title="Standing rate"
            description="The rate every sale earns, and what it costs you"
          >
            <RateSection />
          </Section>
        )}

        {showCategories && (
          <Section
            title="Product categories"
            description="Different rates — or no cashback — for parts of your catalogue"
          >
            <ProductCategoriesSection />
          </Section>
        )}

        {showPreferences && (
          <Section
            title="Earning rules"
            description="How your store earns and settles"
          >
            <PreferencesSection />
          </Section>
        )}
      </div>
    </div>
  );
}

function Section({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-col gap-0.5">
        <h2 className="text-base font-semibold text-mono">{title}</h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {children}
    </section>
  );
}
