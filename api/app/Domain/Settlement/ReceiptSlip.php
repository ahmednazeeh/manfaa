<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\MerchantUser;

/**
 * The stored receipt attached to a settlement payment: where the file sits
 * on the private `slips` disk, what the BYTES said it is, how big it was,
 * and which merchant user uploaded it.
 *
 * `pathOnly` exists for the documented admin fallback (§1: "Admin-side
 * recording remains as the documented fallback"), where an admin records a
 * transfer they reconciled off a bank statement and references a slip the
 * platform did not receive through the upload path — provenance columns stay
 * null rather than being invented.
 */
final readonly class ReceiptSlip
{
    public function __construct(
        public string $path,
        public ?string $mime = null,
        public ?int $sizeBytes = null,
        public ?int $uploadedBy = null,
    ) {}

    /**
     * @param  array{mime: string, extension: string, size: int}  $inspection  SlipStorage::inspect result
     */
    public static function uploaded(string $path, array $inspection, MerchantUser $uploader): self
    {
        return new self($path, $inspection['mime'], $inspection['size'], $uploader->id);
    }

    public static function pathOnly(string $path): self
    {
        return new self($path);
    }
}
