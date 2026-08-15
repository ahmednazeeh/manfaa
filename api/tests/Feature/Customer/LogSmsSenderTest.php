<?php

declare(strict_types=1);

use App\Domain\Customers\LogSmsSender;
use App\Domain\Customers\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Runs the sender while listening on the real log pipeline, and returns the one SMS line it wrote. */
function capturedSmsLog(callable $send): array
{
    $lines = [];

    Log::listen(function (MessageLogged $logged) use (&$lines): void {
        $lines[] = ['message' => $logged->message, 'context' => $logged->context];
    });

    $send();

    $sms = array_values(array_filter($lines, fn (array $line): bool => str_contains($line['message'], 'SMS')));

    expect($sms)->toHaveCount(1);

    return $sms[0];
}

it('masks the code and the phone — the dev driver is not an OTP leak', function () {
    $line = capturedSmsLog(fn () => (new LogSmsSender)->send(
        '+9607712345',
        'Your Manfaa verification code is 482917. It expires in 10 minutes.',
    ));

    $flat = $line['message'].' '.json_encode($line['context']);

    expect($flat)->not->toContain('482917')
        ->and($flat)->not->toContain('+9607712345')
        ->and($flat)->not->toContain('7712345');

    // Masked, not merely omitted: the line still says which number and which
    // message, so the log stays useful for delivery debugging.
    expect($line['context']['phone'])->toBe('+960****345')
        ->and($line['context']['message'])->toContain('Your Manfaa verification code is ******.')
        ->and($line['context']['message'])->toContain('10 minutes');
});

it('leaks no digit run long enough to be a code, whatever the template says', function () {
    foreach ([
        'Your code is 000000.',
        'Store signup code 999999 expires in 10 minutes.',
        'Code: 12345678 — reference 4821',
    ] as $body) {
        $line = capturedSmsLog(fn () => (new LogSmsSender)->send('+9609998877', $body));

        expect($line['context']['message'])->not->toMatch('/\d{3,}/');
    }
});

it('leaks nothing when the real signup flow runs on the dev driver', function () {
    // Exactly the production-misconfiguration scenario: no provider key, so
    // the container falls back to LogSmsSender on a live signup.
    $this->app->instance(SmsSender::class, new LogSmsSender);
    $this->withHeader('Referer', 'http://localhost');

    $line = capturedSmsLog(fn () => $this->postJson('/api/customer/auth/request-otp', ['phone' => '+9607712345'])
        ->assertOk());

    $flat = $line['message'].' '.json_encode($line['context']);

    // Any 6-digit run in the log would BE the code; any 7-digit run would be
    // the mobile.
    expect($flat)->not->toMatch('/\d{6}/')
        ->and($flat)->not->toContain('7712345');
});
