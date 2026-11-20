<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'nickname' => fake()->name(),
            'member_group_id' => MemberGroup::factory(),
            'email' => fake()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => false,
        ]);
    }
}
