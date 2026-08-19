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
