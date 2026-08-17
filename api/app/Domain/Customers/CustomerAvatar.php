<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a customer profile picture lives and how it is addressed.
 *
 * Storage mirrors the merchant logo (App\Domain\Onboarding\MerchantLogo):
 * the PRIVATE `avatars` disk, path customers/{id}/{uuid}.{ext}, previous
 * file deleted only after the replacement is safely on disk.
 *
 * Serving deliberately does NOT mirror the logo's authorising controller.
 * A logo's visibility depends on the merchant row (approved or not) and so
 * must be decided per request; an avatar has no such state — the only
 * requirement is that strangers cannot LOOK ONE UP. The uuid filename in
 * the URL is that guarantee: 122 random bits nobody is ever told except the
 * account's own clients, minted fresh on every upload and dead (404) the
 * moment the picture is replaced or removed. In exchange the URL works as a
 * plain <img>/Image.network on every surface — the mobile app's image
 * loader sends no bearer token, and an auth-gated avatar would force auth
 * plumbing through every widget that paints a circle.
 *
 * The uuid also makes every upload a NEW URL, so the response is cached
 * immutably and a replaced picture can never be served stale.
 */
final class CustomerAvatar
{
    public const string DISK = 'avatars';

    /**
     * The addressable URL for the stored avatar, or null when the customer
     * has none. Built with url() — against the current request's host —
     * for the same reason MerchantLogo does: every app host serves /api/*
     * from this Laravel origin, so the URL is same-origin wherever it is
     * rendered (and, being a capability URL, it also works cross-origin).
     */
    public static function url(Customer $customer): ?string
    {
        $path = $customer->avatar_path;

        if ($path === null || $path === '') {
            return null;
        }

        return url(sprintf('/api/customers/%d/avatar/%s', $customer->getKey(), basename($path)));
    }

    /**
     * Store the (already-validated) upload, delete the previous file, and
     * persist the new path. Returns the new avatar URL.
     */
    public static function store(Customer $customer, UploadedFile $file): string
    {
        $extension = strtolower($file->extension());

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $disk = Storage::disk(self::DISK);
        $previous = $customer->avatar_path;

        $path = $file->storeAs(
            'customers/'.$customer->getKey(),
            Str::uuid()->toString().'.'.$extension,
            self::DISK,
        );

        if ($path === false) {
            abort(500, 'Could not store the avatar.');
        }

        // Only after the replacement is safely on disk — a failed write must
        // never leave the account with no picture at all.
        if ($previous !== null && $previous !== '' && $previous !== $path) {
            $disk->delete($previous);
        }

        $customer->avatar_path = $path;
        $customer->save();

        return (string) self::url($customer);
    }

    /** Delete the file and null the column. A no-op when there is none. */
    public static function remove(Customer $customer): void
    {
        $path = $customer->avatar_path;

        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }

        $customer->avatar_path = null;
        $customer->save();
    }

    /**
     * Response Content-Type derived from the extension chosen at upload —
     * the validator established the bytes are a raster image of that type;
     * the client's claimed Content-Type is never echoed back.
     */
    public static function mime(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
