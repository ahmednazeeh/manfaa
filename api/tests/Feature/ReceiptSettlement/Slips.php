<?php

declare(strict_types=1);

namespace Tests\Feature\ReceiptSettlement;

use Illuminate\Http\UploadedFile;

/**
 * Uploads with REAL leading bytes. `UploadedFile::fake()->create()` writes a
 * file full of zeroes, which no signature check can accept (nor should it) —
 * every fixture here writes the actual magic bytes of the format it claims,
 * so the validation matrix exercises the sniffing rather than the filename.
 *
 * Not a *Test.php file — PHPUnit never collects it; tests reach it through
 * the Tests\ PSR-4 map.
 */
final class Slips
{
    public static function jpeg(string $name = 'slip.jpg'): UploadedFile
    {
        return self::withContent($name, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat("\x00", 64));
    }

    public static function png(string $name = 'slip.png'): UploadedFile
    {
        return self::withContent($name, "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64));
    }

    public static function webp(string $name = 'slip.webp'): UploadedFile
    {
        return self::withContent($name, 'RIFF'."\x24\x00\x00\x00".'WEBPVP8 '.str_repeat("\x00", 32));
    }

    public static function pdf(string $name = 'slip.pdf'): UploadedFile
    {
        return self::withContent($name, "%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n%%EOF\n");
    }

    /** An SVG is a scriptable document, never an accepted slip. */
    public static function svg(string $name = 'slip.svg'): UploadedFile
    {
        return self::withContent($name, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    }

    /**
     * The spoof: HTML bytes wearing a .png name and an image/png
     * Content-Type. Only reading the file refuses this.
     */
    public static function spoofedPng(string $name = 'slip.png'): UploadedFile
    {
        return self::withContent($name, '<html><body>not an image</body></html>', 'image/png');
    }

    /** Over the 5 MB ceiling, with a genuine JPEG header. */
    public static function oversizeJpeg(string $name = 'huge.jpg'): UploadedFile
    {
        return self::withContent($name, "\xFF\xD8\xFF\xE0".str_repeat('x', 5 * 1024 * 1024 + 1));
    }

    public static function empty(string $name = 'empty.jpg'): UploadedFile
    {
        return self::withContent($name, '');
    }

    private static function withContent(string $name, string $content, ?string $mimeType = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'slip');
        file_put_contents($path, $content);

        // test: true keeps the file readable without a real HTTP upload; the
        // mime type is the CLIENT's claim, exactly as a browser would send it.
        return new UploadedFile($path, $name, $mimeType, test: true);
    }
}
