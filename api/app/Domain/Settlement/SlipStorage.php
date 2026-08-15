<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Merchant;
use App\Models\Settlement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Payment-slip storage for the receipt-first flow (PLAN §1). Two rules, both
 * non-negotiable:
 *
 *  1. **The bytes decide the type, never the filename.** A slip is accepted
 *     only when its leading bytes match one of four signatures — JPEG, PNG,
 *     WebP, PDF. `evidence.svg` renamed to `slip.png`, an HTML page with a
 *     .pdf extension, or a polyglot whose Content-Type header claims
 *     image/png are all refused, and the extension we store is the one the
 *     SIGNATURE implies — not the one the client sent. SVG is deliberately
 *     absent from the accepted set: it is a script-bearing document, and a
 *     stored slip is opened by an admin.
 *
 *  2. **Private disk, no URL.** Files land on the `slips` disk
 *     (storage/app/slips), which has no `url` and no `serve` — nothing under
 *     it is reachable over HTTP by any path, signed or not. Admins read a
 *     slip only by streaming it through the authenticated controller.
 *
 * Path: settlements/{merchant}/{settlement}/{uuid}.{ext} — the uuid means a
 * leaked path cannot be walked to a sibling merchant's receipt, and a second
 * upload never overwrites a first (append-only in spirit: history keeps
 * every slip that was ever offered).
 */
final class SlipStorage
{
    public const string DISK = 'slips';

    /** 5 MB — a phone photo of a transfer confirmation, with room to spare. */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Magic-byte signatures, longest-prefix first. WebP needs two probes
     * (RIFF....WEBP) so it is checked separately.
     *
     * @var array<string, array{0: string, 1: string}> signature => [mime, extension]
     */
    private const array SIGNATURES = [
        "\x89PNG\r\n\x1a\n" => ['image/png', 'png'],
        "\xFF\xD8\xFF" => ['image/jpeg', 'jpg'],
        '%PDF-' => ['application/pdf', 'pdf'],
    ];

    /**
     * Validates the upload by CONTENT and returns what the bytes actually
     * are. Size is checked first: a 40 MB "slip" is refused before anything
     * reads it.
     *
     * @return array{mime: string, extension: string, size: int}
     *
     * @throws InvalidSlipException
     */
    public function inspect(UploadedFile $file): array
    {
        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw InvalidSlipException::unsupported();
        }

        if ($size > self::MAX_BYTES) {
            throw InvalidSlipException::tooLarge($size, self::MAX_BYTES);
        }

        $head = (string) @file_get_contents($file->getRealPath(), length: 16);

        foreach (self::SIGNATURES as $signature => [$mime, $extension]) {
            if (str_starts_with($head, $signature)) {
                return ['mime' => $mime, 'extension' => $extension, 'size' => $size];
            }
        }

        // RIFF<4-byte little-endian length>WEBP — the length varies, so the
        // container and the form are probed at their fixed offsets.
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return ['mime' => 'image/webp', 'extension' => 'webp', 'size' => $size];
        }

        throw InvalidSlipException::unsupported();
    }

    /**
     * Stores an already-inspected slip and returns its disk-relative path.
     * The extension comes from the inspection, so a spoofed filename cannot
     * decide what the stored file is called.
     *
     * @param  array{mime: string, extension: string, size: int}  $inspection
     *
     * @throws SlipWriteFailedException the disk refused the write
     */
    public function store(Merchant $merchant, Settlement $settlement, UploadedFile $file, array $inspection): string
    {
        $directory = sprintf('settlements/%d/%d', $merchant->id, $settlement->id);
        $name = sprintf('%s.%s', (string) Str::uuid(), $inspection['extension']);
        $path = $directory.'/'.$name;

        // putFileAs streams the upload; a 5 MB slip never sits in memory. It
        // returns FALSE rather than raising on a failed write, because this
        // disk is configured throw=false — and report=false suppresses even
        // the log line. Discarding that return value would hand back a path
        // to a file that does not exist, and the caller would commit a
        // payment_review settlement whose slip the admin's review route can
        // only 404 on: PLAN §1's "no settlement without a receipt" broken in
        // silence. Throwing rolls the whole submission back instead.
        if (Storage::disk(self::DISK)->putFileAs($directory, $file, $name) === false) {
            throw SlipWriteFailedException::at($path);
        }

        return $path;
    }

    /**
     * Best-effort cleanup for a slip whose surrounding DB transaction rolled
     * back. A file write cannot join a database transaction, so the orphan is
     * deleted explicitly; a failure here leaves an unreferenced private file,
     * which is harmless.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
