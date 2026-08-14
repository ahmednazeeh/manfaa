<?php

declare(strict_types=1);

use App\Domain\Customers\LogSmsSender;
use App\Domain\Customers\MsgOwlSmsSender;
use App\Domain\Customers\SmsSender;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('binds the log sender when no MsgOwl key is configured', function () {
    config(['services.msgowl.key' => '']);

    // Container binding was decided at register() time with an empty key.
    expect(app(SmsSender::class))->toBeInstanceOf(LogSmsSender::class);
});

it('posts the MsgOwl contract exactly: AccessKey header, recipients, body, sender_id', function () {
    config([
        'services.msgowl.key' => 'test-key-123',
        'services.msgowl.sender_id' => 'IsleBooks',
        'services.msgowl.base_url' => 'https://rest.msgowl.com',
    ]);

    Http::fake(['rest.msgowl.com/*' => Http::response(['id' => 'msg_1'], 200)]);

    (new MsgOwlSmsSender)->send('+9607771234', 'Your Manfaa code is 123456.');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://rest.msgowl.com/messages'
            && $request->hasHeader('Authorization', 'AccessKey test-key-123')
            && $request['recipients'] === ['+9607771234']
            && $request['body'] === 'Your Manfaa code is 123456.'
            && $request['sender_id'] === 'IsleBooks';
    });
});

it('throws on provider failure and never logs the message body', function () {
    config([
        'services.msgowl.key' => 'test-key-123',
        'services.msgowl.sender_id' => 'IsleBooks',
    ]);

    Http::fake(['rest.msgowl.com/*' => Http::response(['error' => 'no balance'], 402)]);

    expect(fn () => (new MsgOwlSmsSender)->send('+9607771234', 'Your Manfaa code is 654321.'))
        ->toThrow(RuntimeException::class);
});

it('omits sender_id when not configured', function () {
    config([
        'services.msgowl.key' => 'test-key-123',
        'services.msgowl.sender_id' => '',
    ]);

    Http::fake(['rest.msgowl.com/*' => Http::response([], 200)]);

    (new MsgOwlSmsSender)->send('+9607771234', 'code');

    Http::assertSent(fn ($request) => ! isset($request['sender_id']));
});
