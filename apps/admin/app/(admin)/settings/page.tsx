'use client';

import Link from 'next/link';
import { ChevronRight } from 'lucide-react';
import { PageHeader } from '@/components/admin/page-header';
import { useAdminUser } from '@/components/auth/admin-guard';
import { SETTINGS_GROUPS } from '@/components/shell/settings-nav';

/**
 * The settings hub (owner, 2026-08-25). /settings used to redirect
 * straight to the first screen, which made the twelve settings pages
 * discoverable only by reading the whole sidebar. It now shows the four
 * groups and what lives in each, rendered from the SAME SETTINGS_GROUPS
 * the sidebar uses — one list, so the two can never drift.
 *
 * Superadmin-only entries are filtered here exactly as the nav filters
 * them; a group left with nothing is not drawn. Display only — the
 * server's guard is what actually enforces access.
 */
export default function SettingsHubPage() {
  const user = useAdminUser();

  const groups = SETTINGS_GROUPS.map((group) => ({
    ...group,
    items: group.items.filter(
      (item) => !item.superadminOnly || user.role === 'superadmin',
    ),
  })).filter((group) => group.items.length > 0);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Settings"
        description="Platform configuration, grouped by what each setting decides."
      />

      <div className="grid gap-5 md:grid-cols-2">
        {groups.map((group) => {
          const GroupIcon = group.icon;

          return (
            <section
              key={group.label}
              className="flex flex-col gap-1 rounded-xl border border-border bg-card p-2"
            >
              <div className="flex items-center gap-2.5 px-3 pt-2 pb-1">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent">
                  <GroupIcon className="size-4 text-foreground" />
                </span>
                <span className="text-sm font-semibold">{group.label}</span>
              </div>

              {group.items.map((item) => {
                const ItemIcon = item.icon;

                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    className="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
                  >
                    <ItemIcon className="size-4 shrink-0" />
                    <span className="min-w-0 flex-1">{item.label}</span>
                    <ChevronRight className="size-3.5 shrink-0 opacity-60 rtl:-scale-x-100" />
                  </Link>
                );
              })}
            </section>
          );
        })}
      </div>
    </div>
  );
}
