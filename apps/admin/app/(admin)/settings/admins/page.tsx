'use client';

import { listAdminUsers } from '@manfaa/api-client';
import { useQuery } from '@tanstack/react-query';
import { ShieldAlert, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '@/lib/api-error';
import { formatDateTime } from '@/lib/format';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertIcon,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardTable } from '@/components/ui/card';
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
import { AdminRowActions } from '@/components/settings/admin-actions';
import { CreateAdminDialog } from '@/components/settings/create-admin-dialog';

export default function AdminsPage() {
  const me = useAdminUser();
  const isSuperadmin = me.role === 'superadmin';

  const query = useQuery({
    queryKey: ['admin', 'admin-users'],
    queryFn: ({ signal }) => listAdminUsers({ signal }),
    enabled: isSuperadmin,
  });

  if (!isSuperadmin) {
    // Display only — the server's EnsureSuperadmin middleware 403s the
    // endpoints regardless of what this client renders.
    return (
      <div className="flex flex-col">
        <PageHeader title="Admins" />
        <Alert variant="warning" appearance="light">
          <AlertIcon>
            <ShieldAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>Superadmin only</AlertTitle>
            <AlertDescription>
              Admin account management requires the superadmin role. Ask an
              existing superadmin if you need access changed.
            </AlertDescription>
          </AlertContent>
        </Alert>
      </div>
    );
  }

  const admins = query.data?.data ?? [];

  return (
    <div className="flex flex-col">
      <PageHeader
        title="Admins"
        description="Accounts are deactivated, never deleted, so audit trails keep resolving. The last active superadmin can be neither demoted nor deactivated."
        actions={<CreateAdminDialog />}
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
                    <TableHead>Admin</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Created</TableHead>
                    <TableHead className="text-end">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {query.isPending ? (
                    Array.from({ length: 4 }).map((_, index) => (
                      <TableRow key={index}>
                        <TableCell colSpan={5}>
                          <Skeleton className="h-6 w-full" />
                        </TableCell>
                      </TableRow>
                    ))
                  ) : admins.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={5}
                        className="py-10 text-center text-muted-foreground"
                      >
                        No admin accounts found.
                      </TableCell>
                    </TableRow>
                  ) : (
                    admins.map((admin) => (
                      <TableRow key={admin.id}>
                        <TableCell>
                          <div className="flex flex-col">
                            <span className="font-medium">
                              {admin.name}
                              {admin.id === me.id ? (
                                <span className="ms-1.5 text-xs text-muted-foreground">
                                  (you)
                                </span>
                              ) : null}
                            </span>
                            <span className="text-xs text-muted-foreground">
                              {admin.email}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell>
                          {admin.role === 'superadmin' ? (
                            <Badge
                              variant="primary"
                              appearance="light"
                              size="sm"
                            >
                              Superadmin
                            </Badge>
                          ) : (
                            <Badge
                              variant="secondary"
                              appearance="light"
                              size="sm"
                            >
                              Admin
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell>
                          {admin.is_active ? (
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
                        </TableCell>
                        <TableCell>
                          {formatDateTime(admin.created_at)}
                        </TableCell>
                        <TableCell className="text-end">
                          <AdminRowActions
                            admin={admin}
                            me={me}
                            admins={admins}
                          />
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
