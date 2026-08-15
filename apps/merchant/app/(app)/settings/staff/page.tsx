'use client';

import { useState } from 'react';
import {
  type CreateMerchantStaffResponse,
  type MerchantStaff,
  type MerchantStaffRole,
} from '@manfaa/api-client';
import { Copy, KeyRound, LoaderCircle, Plus, UserRound } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import {
  apiErrorMessage,
  useCreateStaff,
  useStaff,
  useUpdateStaff,
} from '@/lib/queries';
import { useLayout } from '@/components/app-layout/context';
import {
  Alert,
  AlertContent,
  AlertDescription,
  AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardHeader,
  CardTable,
  CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { toast } from 'sonner';
import {
  Toolbar,
  ToolbarActions,
  ToolbarDescription,
  ToolbarHeading,
  ToolbarPageTitle,
} from '@/components/app-layout/toolbar';
import {
  EmptyBlock,
  ErrorBlock,
  LoadingBlock,
} from '@/components/app/async-states';

/**
 * Staff management. There is deliberately no delete — deactivation is the
 * only removal, so the audit trail keeps its actors. The API refuses to
 * demote or deactivate the last active owner (422); the same guards appear
 * here as disabled controls so the dead end is visible before the request.
 */

/**
 * The three tiers, highest first. The picker lists the names only — the
 * selected tier's one-line description is rendered under the control, the
 * pattern the rest of the panel uses (profile channel, setup wizard), and
 * the one that keeps the closed trigger to a single word.
 */
const ROLE_OPTIONS: MerchantStaffRole[] = ['owner', 'manager', 'staff'];

function RoleOptions() {
  const { t } = useTranslation();

  return (
    <>
      {ROLE_OPTIONS.map((role) => (
        <SelectItem key={role} value={role}>
          {t(`roles.${role}`)}
        </SelectItem>
      ))}
    </>
  );
}

function CreateStaffDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (response: CreateMerchantStaffResponse) => void;
}) {
  const { t } = useTranslation();
  const createStaff = useCreateStaff();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<MerchantStaffRole>('staff');

  const canSubmit =
    name.trim() !== '' && email.trim() !== '' && !createStaff.isPending;

  const submit = () => {
    createStaff.mutate(
      { name: name.trim(), email: email.trim(), role },
      {
        onSuccess: (response) => {
          onCreated(response);
        },
        onError: (error) =>
          toast.error(
            apiErrorMessage(error, 'Could not create the account.'),
          ),
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>New account</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-5">
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="staff-name">Name</Label>
            <Input
              id="staff-name"
              value={name}
              maxLength={255}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="staff-email">Email</Label>
            <Input
              id="staff-email"
              type="email"
              value={email}
              maxLength={255}
              onChange={(event) => setEmail(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              They log in with this email.
            </p>
          </div>
          <div className="flex flex-col gap-2.5">
            <Label htmlFor="staff-role">{t('roles.pickerLabel')}</Label>
            <Select
              value={role}
              onValueChange={(value) => setRole(value as MerchantStaffRole)}
            >
              <SelectTrigger id="staff-role">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <RoleOptions />
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {t(`roles.${role}Hint`)} {t('roles.inviteHint')}
            </p>
          </div>
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button disabled={!canSubmit} onClick={submit}>
            {createStaff.isPending ? (
              <LoaderCircle className="animate-spin" />
            ) : (
              <UserRound />
            )}
            Create account
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * The one-time password handover. The password exists only in this response
 * — only its hash survives server-side — so the dialog cannot be dismissed
 * until the owner confirms it has been passed on.
 */
function TempPasswordDialog({
  created,
  onDone,
}: {
  created: CreateMerchantStaffResponse;
  onDone: () => void;
}) {
  const [acknowledged, setAcknowledged] = useState(false);
  const { copyToClipboard } = useCopyToClipboard();

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        // Locked until acknowledged: ignore escape/overlay closes.
        if (!open && acknowledged) onDone();
      }}
    >
      <DialogContent className="max-w-md" showCloseButton={false}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <KeyRound className="size-4.5" />
            One-time password for {created.data.name}
          </DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant="warning" appearance="light">
            <AlertContent>
              <AlertTitle>Shown exactly once.</AlertTitle>
              <AlertDescription>
                We only keep a scrambled copy, so this password cannot be
                shown again. Pass it to {created.data.name} now; they should
                change it after their first login.
              </AlertDescription>
            </AlertContent>
          </Alert>

          <div className="flex flex-col gap-1.5 text-sm">
            <span className="text-muted-foreground">Login email</span>
            <span className="text-mono">{created.data.email}</span>
          </div>

          <div className="flex items-center gap-2">
            <code
              dir="ltr"
              className="grow rounded-md border border-border bg-muted px-3 py-2 text-sm text-mono tracking-wide"
            >
              {created.temp_password}
            </code>
            <Button
              variant="outline"
              mode="icon"
              aria-label="Copy password"
              onClick={() => {
                copyToClipboard(created.temp_password);
                toast.success('Password copied');
              }}
            >
              <Copy />
            </Button>
          </div>

          <label className="flex items-start gap-2.5 text-sm cursor-pointer">
            <Checkbox
              checked={acknowledged}
              onCheckedChange={(checked) => setAcknowledged(checked === true)}
              className="mt-0.5"
            />
            <span>
              I have securely shared this password and understand it will not
              be shown again.
            </span>
          </label>
        </DialogBody>
        <DialogFooter>
          <Button disabled={!acknowledged} onClick={onDone}>
            Done
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function StaffSettingsPage() {
  const { t } = useTranslation();
  const { me } = useLayout();
  const staff = useStaff();
  const updateStaff = useUpdateStaff();

  const [creating, setCreating] = useState(false);
  const [created, setCreated] = useState<CreateMerchantStaffResponse | null>(
    null,
  );

  const activeOwners =
    staff.data?.filter((user) => user.role === 'owner' && user.is_active) ?? [];

  const mutate = (
    user: MerchantStaff,
    body: { role?: MerchantStaffRole; is_active?: boolean },
    success: string,
  ) => {
    updateStaff.mutate(
      { id: user.id, body },
      {
        onSuccess: () => toast.success(success),
        onError: (error) =>
          toast.error(
            apiErrorMessage(error, 'Could not update the account.'),
          ),
      },
    );
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>Staff</ToolbarPageTitle>
          <ToolbarDescription>
            Panel accounts for your store — owners change everything,
            managers run the shop, staff credit customers
          </ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          <Button onClick={() => setCreating(true)}>
            <Plus />
            Add account
          </Button>
        </ToolbarActions>
      </Toolbar>

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {staff.data ? `${staff.data.length} accounts` : 'Accounts'}
          </CardTitle>
        </CardHeader>

        {staff.error ? (
          <ErrorBlock error={staff.error} />
        ) : !staff.data ? (
          <LoadingBlock lines={4} />
        ) : staff.data.length === 0 ? (
          <EmptyBlock>No accounts yet.</EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="w-56">Manage</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {staff.data.map((user) => {
                    const isSelf = user.id === me.id;
                    const isLastActiveOwner =
                      user.role === 'owner' &&
                      user.is_active &&
                      activeOwners.length === 1;
                    // Self and last-owner guards surface as disabled
                    // controls — the API enforces both anyway.
                    const locked = isSelf || isLastActiveOwner;

                    return (
                      <TableRow key={user.id}>
                        <TableCell>
                          <div className="font-medium">
                            {user.name}
                            {isSelf && (
                              <span className="ms-1.5 text-xs font-normal text-muted-foreground">
                                (you)
                              </span>
                            )}
                          </div>
                          <div className="text-xs text-muted-foreground">
                            {user.email}
                          </div>
                        </TableCell>
                        <TableCell>
                          <Badge
                            variant={
                              user.role === 'owner'
                                ? 'primary'
                                : user.role === 'manager'
                                  ? 'info'
                                  : 'secondary'
                            }
                            appearance="light"
                            size="sm"
                          >
                            {t(`roles.${user.role}`)}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Badge
                            variant={user.is_active ? 'success' : 'secondary'}
                            appearance="light"
                            size="sm"
                          >
                            {user.is_active ? 'Active' : 'Inactive'}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center gap-4">
                            <Select
                              value={user.role}
                              disabled={locked || updateStaff.isPending}
                              onValueChange={(value) =>
                                mutate(
                                  user,
                                  { role: value as MerchantStaffRole },
                                  t('roles.changed', {
                                    name: user.name,
                                    role: t(`roles.${value}`),
                                  }),
                                )
                              }
                            >
                              <SelectTrigger
                                className="w-32"
                                size="sm"
                                aria-label={`Role of ${user.name}`}
                              >
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <RoleOptions />
                              </SelectContent>
                            </Select>
                            <div className="flex items-center gap-2">
                              <Switch
                                size="sm"
                                checked={user.is_active}
                                disabled={locked || updateStaff.isPending}
                                aria-label={
                                  user.is_active
                                    ? `Deactivate ${user.name}`
                                    : `Activate ${user.name}`
                                }
                                onCheckedChange={(checked) =>
                                  mutate(
                                    user,
                                    { is_active: checked },
                                    checked
                                      ? `${user.name} activated`
                                      : `${user.name} deactivated`,
                                  )
                                }
                              />
                            </div>
                          </div>
                          <div className="text-xs text-muted-foreground mt-1">
                            {t(`roles.${user.role}Hint`)}
                          </div>
                          {locked && (
                            <div className="text-xs text-muted-foreground mt-1">
                              {isSelf
                                ? 'You cannot change your own account.'
                                : 'The last active owner cannot be demoted or deactivated.'}
                            </div>
                          )}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          </CardTable>
        )}
      </Card>

      <CreateStaffDialog
        key={creating ? 'create-open' : 'create-closed'}
        open={creating}
        onOpenChange={setCreating}
        onCreated={(response) => {
          setCreating(false);
          setCreated(response);
        }}
      />

      {created && (
        <TempPasswordDialog
          created={created}
          onDone={() => setCreated(null)}
        />
      )}
    </div>
  );
}
