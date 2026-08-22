<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Reads the text off an uploaded settlement receipt.
 *
 * Not to extract "the payer" — receipts have no common layout, and parsing a
 * name out of an arbitrary one is guesswork. The text is kept whole so the
 * question can be asked the other way round: does the name the BANK gives
 * for a credit appear on the slip the merchant uploaded? That is what a
 * person does when they check a slip by eye, and it needs no layout rules.
 *
 * A PDF is read directly when it carries a text layer — most banking apps
 * export one, and it is exact where OCR only guesses. Only a scan or a
 * screenshot falls through to tesseract.
 *
 * Every failure returns null. A receipt that cannot be read is not an error:
 * it simply leaves auto-matching to the reference and the registered name,
 * exactly as before this existed.
 */
final class ReceiptReader
{
    /** OCR on a phone screenshot is quick; a stuck binary must not be. */
    private const int TIMEOUT_SECONDS = 40;

    /** Enough of a receipt to hold every name on it, and no more. */
    private const int MAX_CHARS = 8000;

    public function read(string $path, ?string $mime): ?string
    {
        $disk = Storage::disk(SlipStorage::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        // Copied out because the tools take a filesystem path, and the disk
        // is not guaranteed to be local.
        $local = tempnam(sys_get_temp_dir(), 'receipt');

        if ($local === false) {
            return null;
        }

        try {
            file_put_contents($local, $disk->get($path));

            $text = str_contains((string) $mime, 'pdf')
                ? $this->fromPdf($local)
                : $this->fromImage($local);

            return $this->tidy($text);
        } catch (\Throwable $e) {
            Log::warning('Could not read a settlement receipt', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($local);
        }
    }

    /**
     * A PDF's own text layer first; OCR of its first page only if it has
     * none (a scan pasted into a PDF).
     */
    private function fromPdf(string $file): ?string
    {
        $text = $this->run(['pdftotext', '-layout', '-q', $file, '-']);

        if ($text !== null && trim($text) !== '') {
            return $text;
        }

        $image = $file.'-page';
        $this->run(['pdftoppm', '-r', '200', '-png', '-f', '1', '-l', '1', $file, $image]);

        // pdftoppm appends its own page suffix.
        foreach (glob($image.'*.png') ?: [] as $rendered) {
            try {
                return $this->fromImage($rendered);
            } finally {
                @unlink($rendered);
            }
        }

        return null;
    }

    private function fromImage(string $file): ?string
    {
        // `stdout` as the output file makes tesseract write to the pipe.
        return $this->run(['tesseract', $file, 'stdout', '-l', 'eng', '--psm', '6']);
    }

    /** @param list<string> $command */
    private function run(array $command): ?string
    {
        $process = new Process($command, timeout: self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (ProcessFailedException|\Throwable) {
            return null;
        }

        return $process->getOutput();
    }

    /**
     * One line, single-spaced, upper-cased — the form a containment test can
     * use without caring how the receipt was laid out.
     */
    private function tidy(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim(mb_strtoupper($text));

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
