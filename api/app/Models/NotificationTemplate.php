<?php

namespace App\Models;

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The words sent at one of the moments in NotificationTemplateKey.
 *
 * There is no create and no delete: the rows are seeded to match the code
 * catalogue exactly, and a template nobody sends would be words no one ever
 * reads. An admin edits the sentence and switches it on or off.
 */
class NotificationTemplate extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => NotificationTemplateKey::class,
            'active' => 'boolean',
            'updated_by' => 'integer',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    /**
     * The body to send — always the English one, by decision (2026-08-17):
     * every notification goes out in English. The body_dv column still
     * exists but is never read; if per-language sending ever returns, this
     * method is the one place that has to learn about it.
     */
    public function body(): string
    {
        return (string) $this->body_en;
    }
}
