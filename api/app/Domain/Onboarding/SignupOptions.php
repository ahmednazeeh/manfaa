<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Rules\ValidationWindowDays;

/**
 * What a signup form has to know BEFORE anybody submits it — published by
 * both doors (GET /merchant/signup/options and its mobile twin) so the
 * validation-window field can render its own limit instead of guessing at
 * one, and so a merchant is told the range while they are choosing rather
 * than after they are refused.
 *
 * The bounds come from the same rule object that enforces them
 * (App\Rules\ValidationWindowDays), which reads them from platform
 * settings: lower the platform ceiling this afternoon and both signup forms
 * offer the lower ceiling on their next load, with no deploy.
 *
 * Public and unauthenticated, like the signup steps themselves. It says
 * nothing about any store — only what the platform currently allows.
 */
final class SignupOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $bounds = ValidationWindowDays::bounds();

        return [
            'validation_window' => $bounds + [
                'label_en' => 'Validation window',
                'label_dv' => 'ވެލިޑޭޝަން މުއްދަތު',
                'help_en' => sprintf(
                    'How many days a sale stays open for returns before its cashback is confirmed. Cashback stays pending until the window ends. Choose between %d and %d days — %d if you are not sure.',
                    $bounds['min_days'],
                    $bounds['max_days'],
                    $bounds['default_days'],
                ),
                'help_dv' => sprintf(
                    'ވިޔަފާރި ރިޓަރންކުރުމަށް ދޭ މުއްދަތު — މި މުއްދަތު ނިމެންދެން ކޭޝްބެކް ހުންނާނީ ޕެންޑިން ގޮތުގައެވެ. %d އާއި %d އާ ދެމެދުގެ ދުވަހުގެ އަދަދެއް ޚިޔާރުކުރައްވާ. ޔަޤީންނުވާނަމަ %d ދުވަސް ބަހައްޓަވާ.',
                    $bounds['min_days'],
                    $bounds['max_days'],
                    $bounds['default_days'],
                ),
                'invalid_en' => ValidationWindowDays::message($bounds['max_days']),
                'invalid_dv' => sprintf(
                    '%d އާއި %d އާ ދެމެދުގެ ދުވަހުގެ އަދަދެއް ލިޔުއްވާ.',
                    $bounds['min_days'],
                    $bounds['max_days'],
                ),
            ],
        ];
    }
}
