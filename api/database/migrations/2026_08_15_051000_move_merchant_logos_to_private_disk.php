<?php

use App\Domain\Onboarding\MerchantLogo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Task #17b privacy fix: store logos leave the world-readable public disk.
 *
 * Until now a logo was written to storage/app/public/merchants/{id}/logo.png
 * and served straight off /storage — a guessable path keyed on an integer
 * merchant id, which leaked the branding of every store that had merely
 * STARTED the setup wizard, months before any superadmin approved it (PLAN
 * §1: "the store is invisible publicly until approved").
 *
 * Files move to the private `logos` disk; MerchantLogoController is now the
 * only reader, and it publishes a logo exactly while its store is active.
 * `logo_path` keeps its value — it was always disk-relative — so this is a
 * pure file move with no schema change and no row rewrite.
 *
 * Both directions are best-effort per file: a merchant whose file is already
 * missing (deleted by hand, or a row that outlived its upload) is skipped
 * rather than failing the deploy, and a file already present at the
 * destination is left alone so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move(Storage::disk('public'), Storage::disk(MerchantLogo::DISK));
    }

    public function down(): void
    {
        $this->move(Storage::disk(MerchantLogo::DISK), Storage::disk('public'));
    }

    private function move(
        Filesystem $from,
        Filesystem $to,
    ): void {
        $paths = DB::table('merchants')
            ->whereNotNull('logo_path')
            ->where('logo_path', '!=', '')
            ->pluck('logo_path');

        foreach ($paths as $path) {
            $path = (string) $path;

            if ($to->exists($path) || ! $from->exists($path)) {
                continue;
            }

            $stream = $from->readStream($path);

            if ($stream === null || $stream === false) {
                continue;
            }

            $written = $to->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($written) {
                $from->delete($path);
            }
        }
    }
};
