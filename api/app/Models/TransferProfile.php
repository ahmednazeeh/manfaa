<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TransferProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One upstream transfer session, with its own accounts. */
class TransferProfile extends Model
{
    /** @use HasFactory<TransferProfileFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Which bank this profile debits — 'mib' or 'bml'. Used to keep a payout
     * inside one bank where we can. Null means "no preference", which is
     * every profile until somebody sets one.
     */
    /**
     * May this profile SEND money?
     *
     * BML cannot: everything we pay out leaves from MIB, whatever bank the
     * payee uses. BML is here to have its history read, nothing else.
     */
    public function canSend(): bool
    {
        return ! (bool) $this->getAttribute('history_only');
    }

    /** BML's history call is a different upstream. */
    public function isBml(): bool
    {
        return str_contains(mb_strtolower((string) $this->segment), 'bml');
    }

    /**
     * The profile name BML wants on the wire (`?profile=CLEVIDEN`).
     *
     * Deliberately NOT `name`. That is a label an admin can retitle from the
     * panel; this is an identifier the bank matches on, and letting a rename
     * break API calls is the kind of coupling nobody finds until it breaks.
     */
    public function upstreamProfile(): ?string
    {
        $value = trim((string) $this->getAttribute('upstream_profile'));

        return $value === '' ? null : $value;
    }

    public function bank(): ?string
    {
        $bank = trim((string) $this->getAttribute('bank'));

        return $bank === '' ? null : mb_strtolower($bank);
    }

    protected function casts(): array
    {
        return ['dual_control' => 'boolean', 'active' => 'boolean', 'is_default' => 'boolean'];
    }

    /** Where a transfer is POSTed. */
    public function endpoint(): string
    {
        return rtrim($this->base_url, '/').'/'.trim($this->segment, '/').'/transfer';
    }
}
