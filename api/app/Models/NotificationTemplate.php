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
     * The body to send. Dhivehi when the store has written one, English
     * otherwise — never an empty message.
     *
     * Customers carry no language preference today, so this is the whole of
     * the language decision. When they do, this method is the one place that
     * has to learn about it.
     */
    public function body(): string
    {
        $dhivehi = trim((string) $this->body_dv);

        return $dhivehi === '' ? (string) $this->body_en : $dhivehi;
    }

    /** True when the Dhivehi body is what body() would send. */
    public function sendsDhivehi(): bool
    {
        return trim((string) $this->body_dv) !== '';
    }
}
