'use client';

import { useState } from 'react';
import {
  apiErrorCode,
  VENDOR_ABILITIES,
  type CreateMerchantCredentialResponse,
  type MerchantCredential,
  type VendorAbility,
} from '@manfaa/api-client';
import { format } from 'date-fns';
import {
  BookOpen,
  Check,
  Copy,
  KeyRound,
  LoaderCircle,
  Plug,
  Plus,
  ShieldOff,
  TriangleAlert,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
  INTEGRATION_GUIDE_URL,
  INTEGRATIONS_EMAIL,
  VENDOR_API_BASE_URL,
} from '@/lib/integration';
import { vendorAbilityHint, vendorAbilityLabel } from '@/lib/labels';
import {
  apiErrorMessage,
  useCreateCredential,
  useCredentials,
  useRevokeCredential,
} from '@/lib/queries';
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard';
import { WebhooksSection } from './webhooks-section';
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
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
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
 * Settings > API access (PLAN §13b task #21) — owner-only self-serve vendor
 * credentials.
 *
 * Three things this screen has to get right:
 *
 *  1. PLAIN LANGUAGE. The owner deciding what to hand a POS company is a
 *     shopkeeper, not a developer, so abilities are described by what they
 *     let the till DO ("Record sales"), never as `transactions:write`.
 *  2. THE TOKEN IS SHOWN ONCE. Only its SHA-256 digest is stored, so the
 *     handover dialog cannot be dismissed until it is acknowledged, and the
 *     value is never written into the query cache.
 *  3. REVOCATION IS THE SAFETY VALVE. It is one click plus a confirm, it
 *     works even while the store is suspended, and it kills exactly one
 *     credential — a merchant switching POS vendor never rotates the rest.
 *
 * The server enforces all of it independently: owner-only (403
 * `owner_required`), approved + trading store (409), five issuances an hour
 * (429) and ten live credentials (422 `credential_cap_reached`).
 */

/** Mirrors CredentialService::MAX_ACTIVE_PER_MERCHANT. Display only. */
const ACTIVE_CAP = 10;

/**
 * Preselected on a fresh wizard: the three a till needs to trade honestly.
 * Reversals are a contractual obligation, not an option (PLAN §1) — without
 * them a refunded sale keeps its cashback — so the box starts ticked and
 * unticking it warns rather than passing silently.
 */
const DEFAULT_ABILITIES: VendorAbility[] = [
  'transactions:write',
  'transactions:reverse',
  'rates:read',
];

type WizardStep = 'partner' | 'permissions' | 'review';

const STEP_ORDER: WizardStep[] = ['partner', 'permissions', 'review'];

/** Keys, never built from the step value (the labels.ts convention). */
const STEP_TITLE_KEYS: Record<WizardStep, string> = {
  partner: 'apiAccess.step.partner',
  permissions: 'apiAccess.step.permissions',
  review: 'apiAccess.step.review',
};

function isRevoked(credential: MerchantCredential): boolean {
  return credential.revoked_at !== null;
}

function formatMoment(value: string | null): string | null {
  if (value === null) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime())
    ? null
    : format(parsed, 'dd MMM yyyy, HH:mm');
}

function AbilityBadges({ abilities }: { abilities: string[] }) {
  const { t } = useTranslation();

  return (
    <div className="flex flex-wrap gap-1.5">
      {abilities.map((ability) => (
        <Badge key={ability} variant="secondary" appearance="light" size="sm">
          {vendorAbilityLabel(t, ability)}
        </Badge>
      ))}
    </div>
  );
}

/**
 * The wizard: partner → permissions → review. Deliberately a three-step
 * dialog rather than one long form — the permissions step is a decision
 * about what a third party may do with the store's money, and it deserves
 * a screen of its own instead of a row of checkboxes under a text input.
 */
function CreateCredentialWizard({
  open,
  activeCount,
  onOpenChange,
  onCreated,
}: {
  open: boolean;
  activeCount: number;
  onOpenChange: (open: boolean) => void;
  onCreated: (response: CreateMerchantCredentialResponse) => void;
}) {
  const { t } = useTranslation();
  const createCredential = useCreateCredential();
  const [step, setStep] = useState<WizardStep>('partner');
  const [label, setLabel] = useState('');
  const [abilities, setAbilities] = useState<VendorAbility[]>([
    ...DEFAULT_ABILITIES,
  ]);

  const trimmedLabel = label.trim();
  const labelReady = trimmedLabel.length >= 2;
  const stepIndex = STEP_ORDER.indexOf(step);
  const missingReversals =
    abilities.includes('transactions:write') &&
    !abilities.includes('transactions:reverse');

  /**
   * Canonical order, not tick order — the review list, the request body and
   * the credential's stored grant then all read the same way, so nothing
   * looks reshuffled between confirming and seeing it in the table.
   */
  const orderedAbilities = VENDOR_ABILITIES.filter((ability) =>
    abilities.includes(ability),
  );

  const toggle = (ability: VendorAbility, checked: boolean) => {
    setAbilities((current) =>
      checked
        ? current.includes(ability)
          ? current
          : [...current, ability]
        : current.filter((value) => value !== ability),
    );
  };

  const submit = () => {
    createCredential.mutate(
      { label: trimmedLabel, abilities: orderedAbilities },
      {
        onSuccess: onCreated,
        // The two refusals an owner can actually hit get translated prose —
        // the server's own message is English-only, and this is a screen a
        // Dhivehi-reading shopkeeper uses.
        onError: (error) => {
          const code = apiErrorCode(error);
          if (code === 'credential_cap_reached') {
            toast.error(t('apiAccess.capBody'));
          } else if (code === 'issuance_rate_limited') {
            toast.error(t('apiAccess.rateLimited'));
          } else if (code === 'store_not_trading') {
            toast.error(t('apiAccess.storeNotTrading'));
          } else {
            toast.error(apiErrorMessage(error, t('apiAccess.createFailed')));
          }
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('apiAccess.wizardTitle')}</DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-5">
          <p className="text-xs text-muted-foreground">
            {t('apiAccess.stepProgress', {
              current: stepIndex + 1,
              total: STEP_ORDER.length,
            })}{' '}
            · {t(STEP_TITLE_KEYS[step])}
          </p>

          {step === 'partner' && (
            <div className="flex flex-col gap-2.5">
              <Label htmlFor="credential-label">
                {t('apiAccess.partnerLabel')}
              </Label>
              <Input
                id="credential-label"
                value={label}
                maxLength={80}
                placeholder={t('apiAccess.partnerPlaceholder')}
                onChange={(event) => setLabel(event.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                {t('apiAccess.partnerHint')}
              </p>
            </div>
          )}

          {step === 'permissions' && (
            <div className="flex flex-col gap-4">
              <p className="text-sm text-muted-foreground">
                {t('apiAccess.permissionsIntro')}
              </p>
              {VENDOR_ABILITIES.map((ability) => (
                <label
                  key={ability}
                  className="flex items-start gap-2.5 cursor-pointer"
                >
                  <Checkbox
                    checked={abilities.includes(ability)}
                    onCheckedChange={(checked) =>
                      toggle(ability, checked === true)
                    }
                    className="mt-0.5"
                  />
                  <span className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium">
                      {vendorAbilityLabel(t, ability)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {vendorAbilityHint(t, ability)}
                    </span>
                  </span>
                </label>
              ))}

              {missingReversals && (
                <Alert variant="warning" appearance="light">
                  <AlertIcon>
                    <TriangleAlert />
                  </AlertIcon>
                  <AlertContent>
                    <AlertTitle>{t('apiAccess.reversalsTitle')}</AlertTitle>
                    <AlertDescription>
                      {t('apiAccess.reversalsBody')}
                    </AlertDescription>
                  </AlertContent>
                </Alert>
              )}
            </div>
          )}

          {step === 'review' && (
            <div className="flex flex-col gap-4">
              <div className="flex flex-col gap-1.5 text-sm">
                <span className="text-muted-foreground">
                  {t('apiAccess.partnerLabel')}
                </span>
                <span className="font-medium">{trimmedLabel}</span>
              </div>
              <div className="flex flex-col gap-2 text-sm">
                <span className="text-muted-foreground">
                  {t('apiAccess.permissionsLabel')}
                </span>
                <AbilityBadges abilities={orderedAbilities} />
              </div>
              <p className="text-xs text-muted-foreground">
                {t('apiAccess.reviewNote', {
                  active: activeCount + 1,
                  cap: ACTIVE_CAP,
                })}
              </p>
            </div>
          )}
        </DialogBody>
        <DialogFooter>
          {stepIndex > 0 ? (
            <Button
              variant="outline"
              disabled={createCredential.isPending}
              onClick={() => setStep(STEP_ORDER[stepIndex - 1])}
            >
              {t('common.back')}
            </Button>
          ) : (
            <Button variant="outline" onClick={() => onOpenChange(false)}>
              {t('common.cancel')}
            </Button>
          )}

          {step === 'review' ? (
            <Button
              disabled={
                !labelReady ||
                abilities.length === 0 ||
                createCredential.isPending
              }
              onClick={submit}
            >
              {createCredential.isPending ? (
                <LoaderCircle className="animate-spin" />
              ) : (
                <KeyRound />
              )}
              {t('apiAccess.create')}
            </Button>
          ) : (
            <Button
              disabled={
                (step === 'partner' && !labelReady) ||
                (step === 'permissions' && abilities.length === 0)
              }
              onClick={() => setStep(STEP_ORDER[stepIndex + 1])}
            >
              {t('common.continue')}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * The one-time handover. The plaintext exists only in this response — the
 * server keeps a digest — so the dialog is locked until the owner confirms
 * they have stored it, exactly like the staff temp-password dialog.
 */
function TokenHandoverDialog({
  created,
  onDone,
}: {
  created: CreateMerchantCredentialResponse;
  onDone: () => void;
}) {
  const { t } = useTranslation();
  const [acknowledged, setAcknowledged] = useState(false);
  const { copyToClipboard } = useCopyToClipboard();

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        // Locked until acknowledged: escape and overlay closes are ignored.
        if (!open && acknowledged) onDone();
      }}
    >
      <DialogContent className="max-w-lg" showCloseButton={false}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <KeyRound className="size-4.5" />
            {t('apiAccess.tokenTitle', {
              partner: created.credential.display_name,
            })}
          </DialogTitle>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-4">
          <Alert variant="warning" appearance="light">
            <AlertContent>
              <AlertTitle>{t('apiAccess.tokenOnceTitle')}</AlertTitle>
              <AlertDescription>
                {t('apiAccess.tokenOnceBody')}
              </AlertDescription>
            </AlertContent>
          </Alert>

          <div className="flex items-start gap-2">
            <code
              dir="ltr"
              className="grow break-all rounded-md border border-border bg-muted px-3 py-2 text-xs text-mono"
            >
              {created.plaintext_token}
            </code>
            <Button
              variant="outline"
              mode="icon"
              aria-label={t('apiAccess.copyToken')}
              onClick={() => {
                copyToClipboard(created.plaintext_token);
                toast.success(t('apiAccess.tokenCopied'));
              }}
            >
              <Copy />
            </Button>
          </div>

          <div className="flex flex-col gap-1.5 text-sm">
            <span className="text-muted-foreground">
              {t('apiAccess.permissionsLabel')}
            </span>
            <AbilityBadges abilities={created.credential.abilities} />
          </div>

          <div className="rounded-md border border-border p-3 text-xs flex flex-col gap-1.5">
            <span className="text-muted-foreground">
              {t('apiAccess.handoverHeading')}
            </span>
            <code dir="ltr" className="text-mono break-all">
              {VENDOR_API_BASE_URL}
            </code>
            <code dir="ltr" className="text-mono break-all">
              Authorization: Bearer &lt;token&gt;
            </code>
          </div>

          <label className="flex items-start gap-2.5 text-sm cursor-pointer">
            <Checkbox
              checked={acknowledged}
              onCheckedChange={(checked) => setAcknowledged(checked === true)}
              className="mt-0.5"
            />
            <span>{t('apiAccess.tokenAcknowledge')}</span>
          </label>
        </DialogBody>
        <DialogFooter>
          <Button disabled={!acknowledged} onClick={onDone}>
            <Check />
            {t('apiAccess.tokenDone')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function ApiAccessSettingsPage() {
  const { t } = useTranslation();
  const credentials = useCredentials();
  const revokeCredential = useRevokeCredential();

  const [wizardOpen, setWizardOpen] = useState(false);
  const [created, setCreated] =
    useState<CreateMerchantCredentialResponse | null>(null);
  const [revoking, setRevoking] = useState<MerchantCredential | null>(null);

  const rows = credentials.data ?? [];
  const activeCount = rows.filter((row) => !isRevoked(row)).length;
  const atCap = activeCount >= ACTIVE_CAP;

  const revoke = (credential: MerchantCredential) => {
    revokeCredential.mutate(credential.id, {
      onSuccess: () => {
        setRevoking(null);
        toast.success(
          t('apiAccess.revoked', { partner: credential.display_name }),
        );
      },
      onError: (error) =>
        toast.error(apiErrorMessage(error, t('apiAccess.revokeFailed'))),
    });
  };

  return (
    <div className="container">
      <Toolbar>
        <ToolbarHeading>
          <ToolbarPageTitle>{t('apiAccess.title')}</ToolbarPageTitle>
          <ToolbarDescription>{t('apiAccess.subtitle')}</ToolbarDescription>
        </ToolbarHeading>
        <ToolbarActions>
          <Button disabled={atCap} onClick={() => setWizardOpen(true)}>
            <Plus />
            {t('apiAccess.newCredential')}
          </Button>
        </ToolbarActions>
      </Toolbar>

      {atCap && (
        <Alert variant="warning" appearance="light" className="mb-5">
          <AlertIcon>
            <TriangleAlert />
          </AlertIcon>
          <AlertContent>
            <AlertTitle>
              {t('apiAccess.capTitle', { cap: ACTIVE_CAP })}
            </AlertTitle>
            <AlertDescription>{t('apiAccess.capBody')}</AlertDescription>
          </AlertContent>
        </Alert>
      )}

      <Card className="mb-7.5">
        <CardHeader>
          <CardTitle>
            {t('apiAccess.activeCount', {
              active: activeCount,
              cap: ACTIVE_CAP,
            })}
          </CardTitle>
        </CardHeader>

        {credentials.error ? (
          <ErrorBlock error={credentials.error} />
        ) : !credentials.data ? (
          <LoadingBlock lines={3} />
        ) : rows.length === 0 ? (
          <EmptyBlock>{t('apiAccess.empty')}</EmptyBlock>
        ) : (
          <CardTable>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('apiAccess.columnPartner')}</TableHead>
                    <TableHead>{t('apiAccess.columnPermissions')}</TableHead>
                    <TableHead>{t('apiAccess.columnCreated')}</TableHead>
                    <TableHead>{t('apiAccess.columnLastUsed')}</TableHead>
                    <TableHead className="w-32" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((credential) => {
                    const revoked = isRevoked(credential);
                    const lastUsed = formatMoment(credential.last_used_at);

                    // Approved on a consent screen rather than typed into
                    // a form: the store owns it either way, but "you pressed
                    // Authorise for this app" is a different fact from "you
                    // made yourself a key", and the row should say which.
                    const connected =
                      credential.pos_vendor !== null &&
                      credential.issuer.type === 'merchant_user';

                    return (
                      <TableRow
                        key={credential.id}
                        className={revoked ? 'opacity-60' : undefined}
                      >
                        <TableCell>
                          <div className="flex flex-wrap items-center gap-1.5">
                            <span className="font-medium">
                              {credential.display_name}
                            </span>
                            {connected && !revoked && (
                              <Badge
                                variant="primary"
                                appearance="light"
                                size="sm"
                              >
                                <Plug className="size-3" />
                                {t('apiAccess.connectedApp')}
                              </Badge>
                            )}
                          </div>
                          <div className="text-xs text-muted-foreground">
                            {connected && credential.connected_from
                              ? t('apiAccess.connectedFromHint', {
                                  name: credential.display_name,
                                  host: credential.connected_from.replace(
                                    /^https:\/\//,
                                    '',
                                  ),
                                })
                              : connected
                              ? t('apiAccess.connectedAppHint', {
                                  name: credential.display_name,
                                })
                              : credential.issuer.type === 'merchant_user'
                                ? t('apiAccess.issuedByStore', {
                                    name:
                                      credential.issuer.name ??
                                      t('apiAccess.issuedByStoreUnknown'),
                                  })
                                : t('apiAccess.issuedByManfaa')}
                          </div>
                          {revoked && (
                            <Badge
                              variant="secondary"
                              appearance="light"
                              size="sm"
                              className="mt-1.5"
                            >
                              {t('apiAccess.revokedOn', {
                                when:
                                  formatMoment(credential.revoked_at) ?? '—',
                              })}
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell>
                          <AbilityBadges abilities={credential.abilities} />
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {formatMoment(credential.created_at) ?? '—'}
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {lastUsed ?? t('apiAccess.neverUsed')}
                        </TableCell>
                        <TableCell>
                          {!revoked && (
                            <Button
                              variant="outline"
                              size="sm"
                              disabled={revokeCredential.isPending}
                              onClick={() => setRevoking(credential)}
                            >
                              <ShieldOff />
                              {t('apiAccess.revoke')}
                            </Button>
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

      {/* A store's own webhook endpoints (owner, 2026-08-22) — the same
          permissions as the credentials above, so it sits on this page. */}
      <WebhooksSection />

      <Card>
        <CardHeader>
          <CardTitle>{t('apiAccess.guideTitle')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4 text-sm">
          <p className="text-muted-foreground">{t('apiAccess.guideBody')}</p>

          <div className="flex flex-col gap-1.5">
            <span className="text-xs text-muted-foreground">
              {t('apiAccess.baseUrlLabel')}
            </span>
            <code dir="ltr" className="text-mono text-xs break-all">
              {VENDOR_API_BASE_URL}
            </code>
          </div>

          <div className="flex flex-wrap gap-2.5">
            <Button variant="outline" size="sm" asChild>
              <a
                href={INTEGRATION_GUIDE_URL}
                target="_blank"
                rel="noreferrer noopener"
              >
                <BookOpen />
                {t('apiAccess.guideLink')}
              </a>
            </Button>
          </div>

          <p className="text-xs text-muted-foreground">
            {t('apiAccess.testingNote')}{' '}
            <a
              dir="ltr"
              className="underline"
              href={`mailto:${INTEGRATIONS_EMAIL}`}
            >
              {INTEGRATIONS_EMAIL}
            </a>
          </p>
        </CardContent>
      </Card>

      <CreateCredentialWizard
        key={wizardOpen ? 'wizard-open' : 'wizard-closed'}
        open={wizardOpen}
        activeCount={activeCount}
        onOpenChange={setWizardOpen}
        onCreated={(response) => {
          setWizardOpen(false);
          setCreated(response);
        }}
      />

      {created && (
        <TokenHandoverDialog
          created={created}
          onDone={() => setCreated(null)}
        />
      )}

      <AlertDialog
        open={revoking !== null}
        onOpenChange={(open) => {
          if (!open) setRevoking(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('apiAccess.revokeTitle', {
                partner: revoking?.display_name ?? '',
              })}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('apiAccess.revokeBody')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              disabled={revokeCredential.isPending}
              onClick={() => revoking && revoke(revoking)}
            >
              {t('apiAccess.revoke')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
