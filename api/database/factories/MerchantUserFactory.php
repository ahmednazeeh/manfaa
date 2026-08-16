<?php

namespace Database\Factories;

use App\Domain\MerchantAccess\RolePresetService;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantUser>
 */
class MerchantUserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Last, and deliberately: the resolver below reads the already
            // expanded merchant_id, and a factory attribute only sees the
            // keys defined ahead of it.
            'merchant_role_id' => $this->preset(RolePresetService::STAFF),
        ];
    }

    public function owner(): static
    {
        return $this->state(['merchant_role_id' => $this->preset(RolePresetService::OWNER)]);
    }

    /** The middle preset (PLAN §13b): rates, promotions, settlements, branches. */
    public function manager(): static
    {
        return $this->state(['merchant_role_id' => $this->preset(RolePresetService::MANAGER)]);
    }

    public function staff(): static
    {
        return $this->state(['merchant_role_id' => $this->preset(RolePresetService::STAFF)]);
    }

    /**
     * An account standing on a role the store made for itself — the case
     * the three presets cannot express, and the one every delegation test
     * needs.
     */
    public function withRole(MerchantRole $role): static
    {
        return $this->state(['merchant_role_id' => $role->getKey()]);
    }

    /**
     * Resolves one of the merchant's preset roles, seeding all three if the
     * store has none yet. Merchants arrive here by every route — a bare
     * Merchant::factory(), a fixture built before this round existed, a
     * store the test wrote by hand — and only the signup path provisions
     * roles of its own accord, so a factory that assumed they were there
     * would hand back a null role, which every gate refuses.
     */
    private function preset(string $slug): Closure
    {
        return fn (array $attributes) => app(RolePresetService::class)
            ->ensure((int) $attributes['merchant_id'], $slug)
            ->getKey();
    }
}
