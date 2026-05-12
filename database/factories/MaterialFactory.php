<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'title' => '【上课资料】'.fake()->sentence(3),
            'type' => Material::TYPE_PDF,
            'file_path' => 'materials/placeholder/'.Str::uuid().'.pdf',
            'external_url' => null,
            'file_size_bytes' => fake()->numberBetween(100_000, 5_000_000),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_published' => true,
            'published_at' => now(),
            'uploaded_by_user_id' => null,
        ];
    }

    public function externalLink(): static
    {
        return $this->state(fn () => [
            'type' => Material::TYPE_EXTERNAL_LINK,
            'file_path' => null,
            'external_url' => fake()->url(),
            'file_size_bytes' => null,
        ]);
    }

    public function videoLink(): static
    {
        return $this->state(fn () => [
            'type' => Material::TYPE_VIDEO_LINK,
            'file_path' => null,
            'external_url' => 'https://drive.google.com/file/d/'.Str::random(28).'/view',
            'file_size_bytes' => null,
        ]);
    }
}
