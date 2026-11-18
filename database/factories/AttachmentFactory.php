<?php

namespace Database\Factories;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = 'txt';

        return [
            'title' => fake()->words(3, true),
            'original_name' => fake()->word().'.'.$extension,
            'category' => 'default',
            'extension' => $extension,
            'mime_type' => 'text/plain',
            'size' => fake()->numberBetween(1, 10000),
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'width' => null,
            'height' => null,
            'disk' => 'local',
            'object_key' => 'attachments/'.now()->format('Ym').'/'.Str::uuid().'.'.$extension,
            'visibility' => Attachment::PRIVATE,
            'is_image' => false,
            'admin_user_id' => null,
        ];
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes): array => [
            'original_name' => fake()->word().'.png',
            'extension' => 'png',
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'is_image' => true,
            'object_key' => 'attachments/'.now()->format('Ym').'/'.Str::uuid().'.png',
        ]);
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes): array => [
            'disk' => 'public',
            'visibility' => Attachment::PUBLIC,
        ]);
    }
}
