<?php

declare(strict_types=1);

use App\Domain\Customers\DhivehiNameWriter;
use App\Jobs\WriteCustomerDhivehiName;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A customer's name in Thaana (owner, 2026-08-21).
 *
 * The writer talks to Anthropic, so it is faked here — these tests are about
 * the RULES around it: that a failure is harmless, that the job never
 * overwrites a person's own correction, and that only Thaana can be saved.
 */
function customerWithName(string $name = 'Ahmed Nazeeh', ?string $dv = null): Customer
{
    return Customer::factory()->create(['name' => $name, 'name_dv' => $dv]);
}

/**
 * The mobile tree is bearer-only, so a real token is the only way in —
 * `Sanctum::actingAs` leaves EnsureMobileToken looking at a TransientToken and
 * the request is refused.
 *
 * @return array<string, string>
 */
function customerBearer(Customer $customer): array
{
    $token = $customer->createToken(
        'customer: test', ['mobile:customer'], now()->addDays(30),
    )->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

it('fills in the Thaana name after registration', function (): void {
    $customer = customerWithName();

    $this->mock(DhivehiNameWriter::class)
        ->shouldReceive('write')->once()->with('Ahmed Nazeeh')
        ->andReturn('އަހްމަދު ނަޒީހު');

    (new WriteCustomerDhivehiName((int) $customer->getKey()))
        ->handle(app(DhivehiNameWriter::class));

    expect($customer->refresh()->name_dv)->toBe('އަހްމަދު ނަޒީހު');
});

it('leaves the column null when the model cannot write the name', function (): void {
    $customer = customerWithName();

    $this->mock(DhivehiNameWriter::class)
        ->shouldReceive('write')->andReturn(null);

    (new WriteCustomerDhivehiName((int) $customer->getKey()))
        ->handle(app(DhivehiNameWriter::class));

    // Null, not empty string: the clients fall back to the English name, and
    // a customer without a Thaana name is not a broken customer.
    expect($customer->refresh()->name_dv)->toBeNull();
});

it('never overwrites a name the customer corrected themselves', function (): void {
    $customer = customerWithName(dv: 'ކަސްޓަމަރު ލިޔުނު');

    $writer = $this->mock(DhivehiNameWriter::class);
    $writer->shouldNotReceive('write');

    (new WriteCustomerDhivehiName((int) $customer->getKey()))
        ->handle(app(DhivehiNameWriter::class));

    expect($customer->refresh()->name_dv)->toBe('ކަސްޓަމަރު ލިޔުނު');
});

it('does not fall over when the customer is gone', function (): void {
    $customer = customerWithName();
    $id = (int) $customer->getKey();
    $customer->delete();

    $this->mock(DhivehiNameWriter::class)->shouldNotReceive('write');

    (new WriteCustomerDhivehiName($id))->handle(app(DhivehiNameWriter::class));

    expect(Customer::query()->find($id))->toBeNull();
});

it('lets the customer correct their own Thaana name', function (): void {
    $customer = customerWithName(dv: 'ގޯސް ނަން');
    $this->patchJson('/api/mobile/v1/customer/profile',
        ['name_dv' => 'އަހްމަދު ނަޒީހު'], customerBearer($customer))
        ->assertOk()
        ->assertJsonPath('data.name_dv', 'އަހްމަދު ނަޒީހު');

    expect($customer->refresh()->name_dv)->toBe('އަހްމަދު ނަޒީހު');
});

it('refuses a correction that is not written in Thaana', function (): void {
    $customer = customerWithName(dv: 'ރަނގަޅު ނަން');
    $this->patchJson('/api/mobile/v1/customer/profile',
        ['name_dv' => 'Ahmed Nazeeh'], customerBearer($customer))
        ->assertStatus(422)
        // This API wraps validation in its own envelope rather than Laravel's.
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.fields.name_dv.0', 'Write the name in Thaana.');

    expect($customer->refresh()->name_dv)->toBe('ރަނގަޅު ނަން');
});

it('refuses a name whose word opens with a vowel sign', function (): void {
    $customer = customerWithName(dv: 'ރަނގަޅު ނަން');

    // Every character is Thaana, so a block test passes it — but a fili must
    // sit on a consonant. The model produced exactly this during the backfill.
    $this->patchJson('/api/mobile/v1/customer/profile',
        ['name_dv' => 'ައަހްމަދު'], customerBearer($customer))
        ->assertStatus(422)
        ->assertJsonPath('error.meta.fields.name_dv.0', 'Write the name in Thaana.');

    expect($customer->refresh()->name_dv)->toBe('ރަނގަޅު ނަން');
});

it('lets the customer clear it back to the English name', function (): void {
    $customer = customerWithName(dv: 'އަހްމަދު');
    $this->patchJson('/api/mobile/v1/customer/profile',
        ['name_dv' => null], customerBearer($customer))->assertOk();

    expect($customer->refresh()->name_dv)->toBeNull();
});

it('serves the Thaana name beside the English one', function (): void {
    $customer = customerWithName(dv: 'އަހްމަދު ނަޒީހު');
    $this->getJson('/api/mobile/v1/customer/me', customerBearer($customer))
        ->assertOk()
        ->assertJsonPath('data.name', 'Ahmed Nazeeh')
        ->assertJsonPath('data.name_dv', 'އަހްމަދު ނަޒީހު');
});

/**
 * What the model answers, and what we are willing to store.
 *
 * Every case below was observed from Claude on 2026-08-21 while testing real
 * Maldivian names — none is hypothetical. `clean()` is private because nothing
 * outside the writer should call it; reflection here is deliberate.
 */
function cleanAnswer(string $raw): ?string
{
    $writer = new App\Domain\Customers\ClaudeDhivehiNameWriter('k', 'claude-opus-5');
    $method = new ReflectionMethod($writer, 'clean');
    $method->setAccessible(true);

    return $method->invoke($writer, $raw);
}

it('keeps the ﷲ ligature — it is how Abdulla is written', function (): void {
    // Owner, 2026-08-21. Rejecting this meant every Abdulla silently got no
    // Dhivehi name, and Abdulla is one of the commonest names in the country.
    expect(cleanAnswer('އަބްދުﷲ ޝަރީފް'))->toBe('އަބްދުﷲ ޝަރީފް');
});

it('drops an invisible zero-width prefix rather than refusing the name', function (): void {
    expect(cleanAnswer("\u{200B}އިބްރާހީމް ވަހީދު"))->toBe('އިބްރާހީމް ވަހީދު');
});

it('takes the name out of an answer that reasoned out loud first', function (): void {
    expect(cleanAnswer("ipraahiimu vaahidu — wait, need Thaana.\n\nއިބްރާހީމް ވަހީދު"))
        ->toBe('އިބްރާހީމް ވަހީދު');
});

it('refuses a mixed-script answer rather than storing half a name', function (): void {
    // "عبدﷲ ޝަރީފް" — part of the NAME is in Arabic. Pulling the Thaana out
    // yields "ﷲ ޝަރީފް", which drops އަބްދު and looks entirely real. This
    // reached a stored value once before the guard existed.
    expect(cleanAnswer('عبدﷲ ޝަރީފް'))->toBeNull();
    expect(cleanAnswer('عبدالله ޝަރީފް'))->toBeNull();
});

it('refuses a word that opens with a vowel sign', function (): void {
    expect(cleanAnswer('ާއިޝަތު އަލީ'))->toBeNull();
});

it('refuses an answer with no Thaana in it at all', function (): void {
    expect(cleanAnswer('ravdhavaDh ravdvavaba'))->toBeNull();
    expect(cleanAnswer(''))->toBeNull();
});
