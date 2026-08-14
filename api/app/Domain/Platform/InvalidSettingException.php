<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use InvalidArgumentException;

final class InvalidSettingException extends InvalidArgumentException
{
    public static function unknownKey(string $key): self
    {
        return new self(sprintf('Unknown platform setting "%s".', $key));
    }

    public static function outOfRange(string $key, int $value, int $min, int $max): self
    {
        return new self(sprintf('Setting "%s" must be an integer between %d and %d, got %d.', $key, $min, $max, $value));
    }
}
