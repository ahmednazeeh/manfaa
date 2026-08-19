'use client';

import { useState } from 'react';
import {
  approveKyb,
  getKybApplication,
  listKybApplications,
  rejectKyb,
  type KybApplication,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/admin/page-header';

/**
 * Marketplace applications (PLAN-marketplace.md §9).
 *
 * A store handed us identity documents and is waiting on a human. The
 * documents stream through an authenticated route — never a public URL, and
 * never a signed link that could be forwarded.
 */
const TABS = [
  { key: 'pending_kyb', label: 'Waiting' },
  { key: 'active', label: 'Approved' },
  { key: 'rejected', label: 'Refused' },
  { key: 'all', label: 'All' },
] as const;

const DOCUMENT_LABEL: Record<string, string> = {
  business_registration: 'Business registration',
  owner_id: 'Owner ID',
  bank_letter: 'Bank letter',
  tin_certificate: 'TIN certificate',
};

const BUSINESS_TYPE: Record<string, string> = {
  sole_prop: 'Sole proprietorship',
  partnership: 'Partnership',
  pvt_ltd: 'Private limited',
  cooperative: 'Cooperative',
};

export default function MarketplaceKybPage() {
  const [tab, setTab] = useState<string>('pending_kyb');
  const [open, setOpen] = useState<number | null>(null);

  const applications = useQuery({
    queryKey: ['admin', 'kyb', tab],
    queryFn: ({ signal }) => listKybApplications(tab, { signal }),
  });

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Marketplace KYB"
        description="Stores applying to sell on the marketplace. Read their papers before approving — an approved store can take money from customers."
      />

      <div className="flex flex-wrap gap-2">
        {TABS.map((entry) => (
          <Button
            key={entry.key}
            size="sm"
            variant={tab === entry.key ? 'primary' : 'outline'}
            onClick={() => setTab(entry.key)}
          >
            {entry.label}
          </Button>
        ))}
      </div>

      {applications.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : applications.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(applications.error)}</AlertDescription>
        </Alert>
      ) : applications.data.data.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            Nothing here.
          </CardContent>
        </Card>
      ) : (
        <div className="flex flex-col gap-3">
          {applications.data.data.map((application) => (
            <ApplicationCard
              key={application.merchant_id}
              application={application}
              open={open === application.merchant_id}
              onToggle={() =>
                setOpen(open === application.merchant_id ? null : application.merchant_id)
              }
            />
          ))}
        </div>
      )}
    </div>
  );
}

function ApplicationCard({
  application,
  open,
  onToggle,
}: {
  application: KybApplication;
  open: boolean;
  onToggle: () => void;
}) {
  const queryClient = useQueryClient();
  const [refusing, setRefusing] = useState(false);
  const [reason, setReason] = useState('');

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['admin', 'kyb'] });

  const detail = useQuery({
    queryKey: ['admin', 'kyb', 'detail', application.merchant_id],
    queryFn: ({ signal }) => getKybApplication(application.merchant_id, { signal }),
    enabled: open,
  });

  const approve = useMutation({
    mutationFn: () => approveKyb(application.merchant_id),
    onSuccess: () => {
      invalidate();
      toast.success(`${application.merchant_name} can now sell on the marketplace.`);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const refuse = useMutation({
    mutationFn: () => rejectKyb(application.merchant_id, reason.trim()),
    onSuccess: () => {
      invalidate();
      setRefusing(false);
      toast.success('Refused, and the store has been told why.');
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  const missing = detail.data?.data.missing_documents ?? [];

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 py-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <button className="font-medium underline-offset-2 hover:underline" onClick={onToggle}>
                {application.merchant_name}
              </button>
              <Badge
                variant={
                  application.state === 'active'
                    ? 'success'
                    : application.state === 'rejected'
                      ? 'destructive'
                      : 'warning'
                }
                appearance="light"
                size="sm"
              >
                {application.state.replace('_', ' ')}
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
              {BUSINESS_TYPE[application.business_type ?? ''] ?? '—'} ·{' '}
              {application.fulfilment ?? '—'} · {application.contact_phone ?? '—'}
            </p>
            {application.submitted_at ? (
              <p className="text-xs text-muted-foreground/80">
                Submitted {formatDateTime(application.submitted_at)}
              </p>
            ) : null}
          </div>
          <Button size="sm" variant="ghost" onClick={onToggle}>
            {open ? 'Hide papers' : 'Read papers'}
          </Button>
        </div>

        {application.rejected_reason ? (
          <Alert variant="destructive" appearance="light" size="sm">
            <AlertIcon>
              <TriangleAlert />
            </AlertIcon>
            <AlertDescription>{application.rejected_reason}</AlertDescription>
          </Alert>
        ) : null}

        {open ? (
          <>
            <Separator />
            {detail.isPending ? (
              <Skeleton className="h-24 w-full" />
            ) : detail.data ? (
              <div className="flex flex-col gap-2">
                {detail.data.data.documents.map((document) => (
                  <div
                    key={document.id}
                    className="flex items-center justify-between gap-3 rounded-lg border border-border p-3"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-medium">
                        {DOCUMENT_LABEL[document.kind] ?? document.kind}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {document.original_name} ·{' '}
                        {Math.round(document.size / 1024)} KB
                      </p>
                    </div>
                    <Button size="sm" variant="outline" asChild>
                      <a
                        href={`/api/admin/marketplace/kyb/${application.merchant_id}/documents/${document.id}`}
                      >
                        <Download className="size-4" />
                        Open
                      </a>
                    </Button>
                  </div>
                ))}

                {missing.length > 0 ? (
                  <Alert variant="warning" appearance="light" size="sm">
                    <AlertIcon>
                      <TriangleAlert />
                    </AlertIcon>
                    <AlertDescription>
                      Still missing:{' '}
                      {missing.map((kind) => DOCUMENT_LABEL[kind] ?? kind).join(', ')}.
                      This application cannot be approved until they are in.
                    </AlertDescription>
                  </Alert>
                ) : null}
              </div>
            ) : null}

            {application.state !== 'active' ? (
              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  disabled={approve.isPending || missing.length > 0}
                  onClick={() => approve.mutate()}
                >
                  Approve
                </Button>
                <Button size="sm" variant="outline" onClick={() => setRefusing(true)}>
                  Refuse
                </Button>
              </div>
            ) : null}
          </>
        ) : null}
      </CardContent>

      <Dialog open={refusing} onOpenChange={setRefusing}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Refuse this application</DialogTitle>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-2.5">
            <Label htmlFor={`kyb-why-${application.merchant_id}`}>Reason</Label>
            <Input
              id={`kyb-why-${application.merchant_id}`}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder="The bank letter is addressed to a different business name."
            />
            <p className="text-xs text-muted-foreground">
              Sent to the store by push and SMS. Their papers are kept, so
              they only re-upload the one that was wrong.
            </p>
          </DialogBody>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRefusing(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={reason.trim().length < 3 || refuse.isPending}
              onClick={() => refuse.mutate()}
            >
              Refuse
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
