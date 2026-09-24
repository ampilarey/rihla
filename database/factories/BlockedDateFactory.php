<?php

namespace Database\Factories;

use App\Models\BlockedDate;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedDate>
 */
class BlockedDateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            'date' => now()->addMonth()->toDateString(),
            'source' => BlockedDate::ADMIN,
        ];
    }
}
