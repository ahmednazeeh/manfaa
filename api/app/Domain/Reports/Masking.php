<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * WHO IS ABOUT TO READ THIS RENDER — the one thing that decides whether a
 * customer's name and a bank account number are printed whole (owner,
 * 2026-08-24).
 *
 * The two answers are not two levels of the same thing; they are two
 * different artefacts:
 *
 *   Masked  the on-screen PREVIEW. Fifty rows an admin glances at while
 *           choosing a period, rendered into a JSON body that a browser
 *           caches, a screenshot catches and a support session shares.
 *           "Ais*** Moh***" and "****4821" are enough to recognise a row.
 *
 *   Full    the .xlsx. A superadmin-only, audited, tax and reconciliation
 *           artefact whose whole job is to be matched line by line against
 *           a bank statement. A masked account number cannot be matched
 *           against anything, and a masked name is not a name MIRA will
 *           accept. This file is the reason the export writes an audit row
 *           and the preview does not.
 *
 * Deliberately an enum passed INTO a report and fixed for that report's
 * lifetime, never a global or a mutable flag. A report instance either is
 * or is not the unmasked one, from construction; the only door from Masked
 * to Full is Report::forExport(), which returns a NEW report, and
 * BaseReport::previewPayload() refuses outright to serialise a Full one.
 * A setter would make "the export ran, then the preview rendered" a way to
 * put real account numbers in a JSON body, and nothing in a code review
 * reliably catches that.
 */
enum Masking
{
    case Masked;

    case Full;
}
