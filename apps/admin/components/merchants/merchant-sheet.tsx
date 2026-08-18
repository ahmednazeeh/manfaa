'use client';

import { ReactNode, useState } from 'react';
import {
  getAdminMerchant,
  listAdminMerchantNotices,
  type AdminMerchantDetail,
  type MerchantNoticeType,
  type MerchantStanding,
} from '@manfaa/api-client';
import { MoneyText } from '@manfaa/ui';
import { useQuery } from '@tanstack/react-query';
import { Eye, TriangleAlert } from 'lucide-react';
import { useAdminUser } from '@/components/auth/admin-guard';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import {
  merchantChannelLabel,
  noticeTypeLabel,
} from '@/lib/labels';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { MerchantStatusBadge } from '@/components/admin/state-badge';
import { BranchEditor } from '@/components/merchants/branch-editor';
import { EditMerchantDialog } from '@/components/merchants/edit-merchant-dialog';
import { MerchantStatusActions } from '@/components/merchants/merchant-status-actions';
import { StaffActions } from '@/components/merchants/staff-actions';
import { ChannelBadge } from '@/components/store-reviews/channel-badge';
import { StoreLogo } from '@/components/store-reviews/store-logo';

const NOTICE_VARIANTS: Record<MerchantNoticeType, BadgeProps['variant']> = {
  reminder_day10: 'info',
  urgent_day13: 'warning',
  due_day15: 'warning',
  suspended: 'destructive',
  reinstated: 'success',
  write_off: 'destructive',
};

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {label}
      </span>
      <div className="text-sm text-foreground">{children}</div>
    </div>
  );
}

function Empty({ children = 'Not provided' }: { children?: ReactNode }) {
  return <span className="text-muted-foreground italic">{children}</span>;
}

function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <h3 className="text-sm font-semibold text-foreground">{children}</h3>
  );
}

/**
 * The full superadmin control surface for one merchant, opened from its
 * standing row: profile, standing, branches with their zones, panel
 * accounts, and the recent notice trail — with Edit, Suspend/Reinstate and
 * the staff rescue actions beside the data they act on. Plain admins see
 * the same record read-only; the server 403s the writes regardless of what
 * this client renders.
 */
export function MerchantSheet({ merchant }: { merchant: MerchantStanding }) {
  const [open, setOpen] = useState(false);
  const me = useAdminUser();
  const isSuperadmin = me.role === 'superadmin';

  const query = useQuery({
    queryKey: ['admin', 'merchant-detail', merchant.id],
    queryFn: ({ signal }) => getAdminMerchant(merchant.id, { signal }),
    enabled: open,
  });

  const notices = useQuery({
    queryKey: ['admin', 'merchant-notices', merchant.id],
    queryFn: ({ signal }) => listAdminMerchantNotices(merchant.id, { signal }),
    enabled: open,
  });

  const detail: AdminMerchantDetail | undefined = query.data?.data;

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm">
          <Eye />
          View
        </Button>
      </SheetTrigger>
      <SheetContent className="sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>{merchant.name}</SheetTitle>
          <SheetDescription>
            Profile, standing, branches and panel accounts —
            {isSuperadmin
              ? ' with the superadmin controls beside what they act on.'
              : ' read-only; changes require the superadmin role.'}
          </SheetDescription>
        </SheetHeader>
        <SheetBody className="grow p-0">
          <ScrollArea className="h-full px-5 py-4">
            {query.isPending ? (
              <div className="flex flex-col gap-3">
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-32 w-full" />
                <Skeleton className="h-24 w-full" />
              </div>
            ) : query.isError ? (
              <Alert variant="destructive" appearance="light" size="sm">
                <AlertIcon>
                  <TriangleAlert />
                </AlertIcon>
                <AlertDescription>
                  {apiErrorMessage(query.error)}
                </AlertDescription>
              </Alert>
            ) : detail !== undefined ? (
              <div className="flex flex-col gap-5 pb-4">
                <div className="flex items-start gap-4">
                  <StoreLogo
                    name={detail.name}
                    logoUrl={detail.logo_url}
                    size="lg"
                  />
                  <div className="flex min-w-0 flex-col gap-1.5">
                    <span className="text-base font-semibold">
                      {detail.name}
                    </span>
                    {detail.name_dv ? (
                      <span dir="rtl" className="text-sm text-muted-foreground">
                        {detail.name_dv}
                      </span>
                    ) : null}
                    <span className="text-xs text-muted-foreground">
                      /store/{detail.slug}
                    </span>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <MerchantStatusBadge status={detail.status} />
                      <ChannelBadge channel={detail.channel} />
                      {detail.featured ? (
                        <Badge variant="warning" appearance="light" size="sm">
                          Featured
                        </Badge>
                      ) : null}
                    </div>
                  </div>
                </div>

                {isSuperadmin ? (
                  <div className="flex flex-wrap items-center gap-2">
                    <EditMerchantDialog merchant={detail} />
                    <MerchantStatusActions merchant={detail} />
                  </div>
                ) : null}

                <Separator />

                <div className="grid grid-cols-2 gap-4">
                  <Field label="Category">
                    {detail.category ?? <Empty>Not chosen</Empty>}
                  </Field>
                  <Field label="Channel">
                    {merchantChannelLabel(detail.channel)}
                  </Field>
                  <Field label="Cashback rate">
                    {detail.cashback_rate_percent === null ? (
                      <Empty>No live rate</Empty>
                    ) : (
                      <span className="font-medium">
                        {detail.cashback_rate_percent}%
                      </span>
                    )}
                  </Field>
                  <Field label="Website">
                    {detail.website_url ? (
                      <a
                        href={detail.website_url}
                        target="_blank"
                        rel="noreferrer"
                        className="break-all text-primary underline underline-offset-2"
                      >
                        {detail.website_url}
                      </a>
                    ) : (
                      <Empty />
                    )}
                  </Field>
                  <Field label="Contact email">
                    {detail.contact_email ?? <Empty />}
                  </Field>
                  <Field label="Contact phone">
                    {detail.contact_phone ?? <Empty />}
                  </Field>
                  <Field label="Support phone">
                    {detail.support_phone ?? <Empty />}
                  </Field>
                  <Field label="Signed up">
                    {formatDateTime(detail.created_at)}
                  </Field>
                </div>

                {detail.eligibility_basis ? (
                  <Field label="Terms & exclusions (shown to customers verbatim)">
                    <p className="rounded-md border border-border bg-muted/40 p-3 whitespace-pre-wrap">
                      {detail.eligibility_basis}
                    </p>
                  </Field>
                ) : null}

                <Separator />

                <div className="flex flex-col gap-3">
                  <SectionTitle>Standing</SectionTitle>
                  <div className="grid grid-cols-2 gap-4">
                    <Field label="Open payables">
                      {detail.standing.open_payable_count}
                    </Field>
                    <Field label="Outstanding">
                      <MoneyText laari={detail.standing.outstanding_laari} />
                    </Field>
                    <Field label="Overdue">
                      <span
                        className={cn(
                          detail.standing.overdue_laari > 0 &&
                            'font-semibold text-destructive',
                        )}
                      >
                        <MoneyText laari={detail.standing.overdue_laari} />
                      </span>
                    </Field>
                    <Field label="Oldest due">
                      {formatDateTime(detail.standing.oldest_due_at)}
                    </Field>
                  </div>
                </div>

                <Separator />

                <div className="flex flex-col gap-3">
                  <SectionTitle>
                    Branches ({detail.branches.length})
                  </SectionTitle>
                  {detail.branches.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                      No branches recorded.
                    </p>
                  ) : (
                    <ul className="flex flex-col gap-2">
                      {detail.branches.map((branch) => (
                        <li
                          key={branch.id}
                          className="flex items-start justify-between gap-3 rounded-lg border border-border p-3"
                        >
                          <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-sm font-medium">
                              {branch.name}
                            </span>
                            {branch.address ? (
                              <span className="text-xs text-muted-foreground">
                                {branch.address}
                              </span>
                            ) : null}
                            {branch.lat !== null && branch.lng !== null ? (
                              <a
                                href={`https://www.google.com/maps/search/?api=1&query=${branch.lat},${branch.lng}`}
                                target="_blank"
                                rel="noreferrer"
                                className="text-xs text-primary underline underline-offset-2"
                              >
                                {branch.lat}, {branch.lng}
                              </a>
                            ) : (
                              <span className="text-xs text-muted-foreground italic">
                                No pin — cannot appear in Nearby
                              </span>
                            )}
                          </div>
                          <div className="flex shrink-0 items-center gap-1.5">
                            <BranchEditor
                              merchantId={detail.id}
                              branch={branch}
                            />
                          {branch.zone !== null ? (
                            <Badge
                              variant="secondary"
                              appearance="light"
                              size="sm"
                            >
                              {branch.zone.name ?? `Zone #${branch.zone.id}`}
                            </Badge>
                          ) : (
                            <Badge
                              variant="secondary"
                              appearance="ghost"
                              size="sm"
                            >
                              Unzoned
                            </Badge>
                          )}
                          </div>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>

                <Separator />

                <div className="flex flex-col gap-3">
                  <SectionTitle>
                    Panel accounts ({detail.staff.length})
                  </SectionTitle>
                  {detail.staff.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                      No panel accounts.
                    </p>
                  ) : (
                    <ul className="flex flex-col gap-2">
                      {detail.staff.map((member) => (
                        <li
                          key={member.id}
                          className="flex flex-col gap-2 rounded-lg border border-border p-3"
                        >
                          <div className="flex items-start justify-between gap-3">
                            <div className="flex min-w-0 flex-col gap-0.5">
                              <span className="text-sm font-medium">
                                {member.name}
                              </span>
                              <span className="break-all text-xs text-muted-foreground">
                                {member.email}
                              </span>
                            </div>
                            <div className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                              {member.role !== null ? (
                                <Badge
                                  variant={
                                    member.role.is_owner
                                      ? 'primary'
                                      : 'secondary'
                                  }
                                  appearance="light"
                                  size="sm"
                                >
                                  {member.role.name}
                                </Badge>
                              ) : (
                                <Badge
                                  variant="destructive"
                                  appearance="light"
                                  size="sm"
                                >
                                  No role
                                </Badge>
                              )}
                              {member.is_active ? (
                                <Badge
                                  variant="success"
                                  appearance="light"
                                  size="sm"
                                >
                                  Active
                                </Badge>
                              ) : (
                                <Badge
                                  variant="destructive"
                                  appearance="light"
                                  size="sm"
                                >
                                  Deactivated
                                </Badge>
                              )}
                            </div>
                          </div>
                          {isSuperadmin ? (
                            <StaffActions
                              merchantId={detail.id}
                              staff={member}
                            />
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>

                <Separator />

                <div className="flex flex-col gap-3">
                  <SectionTitle>Recent notices</SectionTitle>
                  {notices.isPending ? (
                    <Skeleton className="h-12 w-full" />
                  ) : notices.isError ? (
                    <p className="text-sm text-muted-foreground">
                      {apiErrorMessage(notices.error)}
                    </p>
                  ) : notices.data.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                      No notices recorded.
                    </p>
                  ) : (
                    <ol className="flex flex-col gap-1.5">
                      {notices.data.data.slice(0, 8).map((notice) => (
                        <li
                          key={notice.id}
                          className="flex items-center justify-between gap-2 rounded-md border border-border px-3 py-2"
                        >
                          <Badge
                            variant={NOTICE_VARIANTS[notice.type]}
                            appearance="light"
                            size="sm"
                          >
                            {noticeTypeLabel(notice.type)}
                          </Badge>
                          <span className="text-xs text-muted-foreground">
                            {formatDateTime(notice.sent_at)}
                          </span>
                        </li>
                      ))}
                    </ol>
                  )}
                  <p className="text-xs text-muted-foreground">
                    The full trail, with payloads, is under the row&apos;s
                    Notices button.
                  </p>
                </div>
              </div>
            ) : null}
          </ScrollArea>
        </SheetBody>
      </SheetContent>
    </Sheet>
  );
}
