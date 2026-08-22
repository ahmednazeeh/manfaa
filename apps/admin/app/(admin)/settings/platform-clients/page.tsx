'use client';

import { useState } from 'react';
import {
  listPlatformClients,
  rotatePlatformClientSecret,
  type PlatformClient,
  type PlatformClientSecretResponse,
} from '@manfaa/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Check,
  Copy,
  KeyRound,
  Pencil,
  ShieldAlert,
  TriangleAlert,
} from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api-error';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardTable } from '@/components/ui/card';
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/admin/page-header';
import { useAdminUser } from '@/components/auth/admin-guard';
import { PlatformClientDialog } from '@/components/settings/platform-client-dialog';

/**
 * Platforms that may put "IsleBooks would like to … Authorise / Deny" in
 * front of a shopkeeper.
 *
 * Superadmin only, and that gate is the whole design: the merchant still
 * decides, but the right to ASK is granted here. A developer without a
 * registration is not stuck — they use the per-merchant key, which the
 * merchant issues themselves and which reaches exactly one shop.
 */
export default function PlatformClientsPage() {
  const me = useAdminUser();
  const isSuperadmin = me.role === 'superadmin';

  const query = useQuery({
    queryKey: ['admin', 'platform-clients'],
    queryFn: ({ signal }) => listPlatformClients({ signal }),
    enabled: isSuperadmin,
  });

  if (!isSuperadmin) {
    // Display only — EnsureSuperadmin 403s the endpoints regardless.
    return (
      <div className="flex flex-col">
        <PageHeader title="Connected platforms" />
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <ShieldAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>Superadmin only</AlertTitle>
            <AlertDescription>
              Registering a platform lets it ask any merchant on Manfaa for
              access, so it takes the superadmin role.
            </AlertDescription>
          </AlertContent>
        </Alert>
      </div>
    );
  }

  const clients = query.data?.data ?? [];
  const abilities = query.data?.meta.abilities ?? [];

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Connected platforms"
        description="Software that may ask merchants for access on a Manfaa consent screen. The merchant approves; the token that follows does not expire, so revocation is the control."
        actions={<PlatformClientDialog abilities={abilities} />}
      />

      {query.isError ? (
        <Alert variant="destructive" appearance="light">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertDescription>{apiErrorMessage(query.error)}</AlertDescription>
        </Alert>
      ) : (
        <Card>
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Platform</TableHead>
                    <TableHead>Client ID</TableHead>
                    <TableHead>May ask for</TableHead>
                    <TableHead>Consent screen</TableHead>
                    <TableHead>Connected shops</TableHead>
                    <TableHead className="text-end">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 3 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={6}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : clients.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={6}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No platforms registered. Developers reach you first —
                        this is the door.
                      </TableCell>
                    </TableRow>
                  ) : (
                    clients.map((client) => (
                      <TableRow key={client.id}>
                        <TableCell>
                          <div className="flex flex-col">
                            <span className="font-medium">
                              {client.display_name || client.name}
                            </span>
                            {client.public_client ? (
                              <span className="text-xs text-muted-foreground">
                                Public client — no secret; stores connect from
                                their own sites
                              </span>
                            ) : null}
                            {client.website ? (
                              <span className="text-xs text-muted-foreground">
                                {client.website}
                              </span>
                            ) : null}
                          </div>
                        </TableCell>
                        <TableCell>
                          <code className="font-mono text-xs text-muted-foreground">
                            {client.client_id ?? '—'}
                          </code>
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-wrap gap-1">
                            {client.allowed_abilities.map((ability) => (
                              <Badge
                                key={ability}
                                variant="secondary"
                                appearance="light"
                                size="sm"
                              >
                                {ability}
                              </Badge>
                            ))}
                          </div>
                        </TableCell>
                        <TableCell>
                          {client.connect_enabled ? (
                            <Badge
                              variant="success"
                              appearance="light"
                              size="sm"
                            >
                              Open
                            </Badge>
                          ) : (
                            <Badge
                              variant="secondary"
                              appearance="light"
                              size="sm"
                            >
                              Shut
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell>{client.connections}</TableCell>
                        <TableCell className="text-end">
                          <div className="flex items-center justify-end gap-1.5">
                            <PlatformClientDialog
                              abilities={abilities}
                              client={client}
                              trigger={
                                <Button variant="outline" size="sm">
                                  <Pencil />
                                  Edit
                                </Button>
                              }
                            />
                            {client.public_client ? null : (
                              <RotateSecret client={client} />
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        </Card>
      )}
    </div>
  );
}

/**
 * A new secret, and every merchant connection this platform holds is cut.
 *
 * Rotation happens because a secret leaked. Leaving the grants alive would
 * mean rotating changed nothing for whoever already has them — so the
 * confirmation says the cost out loud, with the count.
 */
function RotateSecret({ client }: { client: PlatformClient }) {
  const queryClient = useQueryClient();
  const [rotated, setRotated] = useState<PlatformClientSecretResponse | null>(
    null,
  );
  const { isCopied, copyToClipboard } = useCopyToClipboard();

  const rotate = useMutation({
    mutationFn: () => rotatePlatformClientSecret(client.id),
    onSuccess: (response) => {
      queryClient.invalidateQueries({
        queryKey: ['admin', 'platform-clients'],
      });
      setRotated(response);
    },
    onError: (error) => toast.error(apiErrorMessage(error)),
  });

  return (
    <>
      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button variant="outline" size="sm" disabled={rotate.isPending}>
            <KeyRound />
            Rotate
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Rotate the secret for {client.display_name || client.name}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              {client.connections === 0
                ? 'No merchant is connected, so nothing breaks — the developer just needs the new secret.'
                : `${client.connections} merchant ${
                    client.connections === 1 ? 'connection' : 'connections'
                  } will stop working immediately. Every shop must approve the platform again.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => rotate.mutate()}>
              Rotate the secret
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <Dialog
        open={rotated !== null}
        onOpenChange={(next) => !next && setRotated(null)}
      >
        <DialogContent className="max-w-md" showCloseButton={false}>
          <DialogHeader>
            <DialogTitle>New secret</DialogTitle>
            <DialogDescription>
              {rotated?.data.connections_revoked
                ? `${rotated.data.connections_revoked} connection(s) were cut. Send this to the developer over a secure channel.`
                : 'Send this to the developer over a secure channel.'}
            </DialogDescription>
          </DialogHeader>
          <DialogBody className="flex items-center gap-2">
            <code className="flex-1 overflow-x-auto rounded-md border border-border bg-muted/50 px-3 py-2.5 font-mono text-sm">
              {rotated?.data.client_secret}
            </code>
            <Button
              type="button"
              variant="outline"
              size="icon"
              aria-label="Copy client secret"
              onClick={() =>
                rotated?.data.client_secret &&
                copyToClipboard(rotated.data.client_secret)
              }
            >
              {isCopied ? <Check className="text-success" /> : <Copy />}
            </Button>
          </DialogBody>
          <DialogFooter>
            <Button type="button" onClick={() => setRotated(null)}>
              Done — I have copied the secret
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
