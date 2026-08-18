<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Product artwork. The lowest `sort` is the card image. */
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    protected $guarded = [];

    /** Public: these are shop photos, not identity documents. */
    public const string DISK = 'public';

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
