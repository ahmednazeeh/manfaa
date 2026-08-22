<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\BrandAsset;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\PlatformBrandAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The superadmin's brand controls.
 *
 * Superadmin only: these five files ARE the platform's face on every public
 * surface, so replacing one is closer to changing the company letterhead
 * than to editing a setting. There is no per-slot history — a brand mark is
 * replaced, not versioned — but the previous file is deleted only after the
 * new one is safely stored.
 */
final class BrandAssetsController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = PlatformBrandAsset::query()->with('updatedBy:id,name')->get()->keyBy('slot');

        return new JsonResponse(['data' => array_map(
            function (string $slot) use ($rows): array {
                /** @var PlatformBrandAsset|null $row */
                $row = $rows->get($slot);

                return [
                    'slot' => $slot,
                    'label' => BrandAsset::SLOTS[$slot]['label'],
                    'shape' => BrandAsset::SLOTS[$slot]['shape'],
                    // Always renderable — the default when nothing is set.
                    'url' => BrandAsset::url($slot),
                    'is_custom' => $row !== null,
                    'original_name' => $row?->original_name,
                    'updated_at' => $row?->updated_at?->toIso8601String(),
                    'updated_by' => $row?->updatedBy?->name,
                ];
            },
            BrandAsset::slots(),
        )]);
    }

    public function store(Request $request, string $slot): JsonResponse
    {
        abort_unless(BrandAsset::isSlot($slot), 404);

        $isFavicon = $slot === 'favicon';

        $request->validate([
            'file' => array_values(array_filter([
                'required',
                'file',
                // A favicon may be an .ico, which is not an "image" to PHP's
                // getimagesize and has no meaningful dimensions to check.
                $isFavicon ? null : 'image',
                $isFavicon ? 'mimes:png,ico,webp' : 'mimes:png,jpg,jpeg,webp',
                'max:'.BrandAsset::MAX_KB,
                // SVG is refused for every slot. It is a document that may
                // carry script, and this one would be served from our own
                // origin on manfaa.app, merchant. and admin. — the widest
                // stored-XSS surface the platform has. The packaged
                // defaults are svg because we wrote them.
                $isFavicon ? null : Rule::dimensions()->minWidth(48)->minHeight(48)->maxWidth(4096)->maxHeight(4096),
            ])),
        ], [
            'file.dimensions' => 'The image must be between 48 and 4096 pixels on each side.',
            'file.mimes' => $isFavicon
                ? 'A favicon must be a PNG, ICO or WebP file.'
                : 'The logo must be a PNG, JPEG or WebP file. SVG cannot be accepted.',
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        $row = PlatformBrandAsset::query()->firstOrNew(['slot' => $slot]);
        $previous = $row->path;

        $path = $file->storeAs('brand', $slot.'-'.Str::uuid()->toString().'.'.$extension, BrandAsset::DISK);

        abort_if($path === false, 500, 'Could not store the file.');

        $row->fill([
            'path' => $path,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 200, ''),
            'updated_by' => $admin->getKey(),
        ])->save();

        // Only once the replacement is committed.
        if ($previous !== null && $previous !== '' && $previous !== $path) {
            Storage::disk(BrandAsset::DISK)->delete($previous);
        }

        return $this->index();
    }

    /** Back to the packaged default. */
    public function destroy(string $slot): JsonResponse
    {
        abort_unless(BrandAsset::isSlot($slot), 404);

        $row = PlatformBrandAsset::query()->where('slot', $slot)->first();

        if ($row !== null) {
            if ($row->path !== null && $row->path !== '') {
                Storage::disk(BrandAsset::DISK)->delete($row->path);
            }

            $row->delete();
        }

        return $this->index();
    }
}
