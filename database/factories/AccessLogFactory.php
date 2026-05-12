<?php

namespace Database\Factories;

use App\Models\AccessLog;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessLog>
 */
class AccessLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'material_id' => Material::factory(),
            'action' => fake()->randomElement([AccessLog::ACTION_VIEW, AccessLog::ACTION_DOWNLOAD]),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'accessed_at' => now(),
        ];
    }
}
