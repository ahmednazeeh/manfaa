<?php

namespace App\Http\Resources;

use App\Models\MerchantProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merchant product category as the merchant panel sees it. The slug is
 * the immutable line key; mode/rate changes affect FUTURE credits only.
 *
 * @mixin MerchantProductCategory
 */
class ProductCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name_en' => $this->name_en,
            'name_dv' => $this->name_dv,
            'mode' => $this->mode,
            'rate_bp' => $this->rate_bp,
            'active' => $this->active,
            'sort' => $this->sort,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
