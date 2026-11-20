<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stays\Models\BlockedDate;
use Modules\Stays\Models\RoomType;

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
