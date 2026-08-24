<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * One column of a report sheet: the machine key the panel reads, the human
 * label the workbook prints, and the type that decides how the value is
 * rendered in both places.
 */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnType $type,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, ColumnType::Text);
    }

    public static function int(string $key, string $label): self
    {
        return new self($key, $label, ColumnType::Int);
    }

    public static function money(string $key, string $label): self
    {
        return new self($key, $label, ColumnType::Money);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, ColumnType::Percent);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, ColumnType::Date);
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
        ];
    }
}
