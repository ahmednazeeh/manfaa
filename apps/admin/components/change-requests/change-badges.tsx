import type {
  ChangeRequestKind,
  ChangeRequestStatus,
} from '@manfaa/api-client';
import { MapPin, Plus, Store, Trash2, type LucideIcon } from 'lucide-react';
import { changeKindLabel, changeRequestStatusLabel } from '@/lib/labels';
import { Badge, BadgeDot, type BadgeProps } from '@/components/ui/badge';

/**
 * The two chips the change queue is scanned by. Same rule as
 * components/admin/state-badge.tsx: the WORDS come from lib/labels.ts
 * (exhaustive over the union), so only the colour is decided here.
 */

/**
 * Kinds are told apart by ICON, not by colour: three of the four are
 * routine, and painting "branch removal" red in a list where every row is
 * still undecided would pre-judge the review. Severity belongs to the
 * status chip beside it.
 */
const KIND_ICONS: Record<ChangeRequestKind, LucideIcon> = {
  profile: Store,
  branch_create: Plus,
  branch_update: MapPin,
  branch_delete: Trash2,
};

export function ChangeKindBadge({ kind }: { kind: ChangeRequestKind }) {
  const Icon = KIND_ICONS[kind];
  return (
    <Badge variant="secondary" appearance="light" size="sm">
      <Icon />
      {changeKindLabel(kind)}
    </Badge>
  );
}

const STATUS_VARIANTS: Record<ChangeRequestStatus, BadgeProps['variant']> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'destructive',
  /** Replaced by a newer submission for the same target — a real terminal state. */
  superseded: 'secondary',
};

export function ChangeStatusBadge({ status }: { status: ChangeRequestStatus }) {
  return (
    <Badge variant={STATUS_VARIANTS[status]} appearance="light" size="sm">
      <BadgeDot />
      {changeRequestStatusLabel(status)}
    </Badge>
  );
}
