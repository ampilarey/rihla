<?php

namespace Tests\Feature;

use App\Filament\Resources\Tasks\Pages\ListCrmTasks;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Whoever may not assign work owns what they make — site audit. The owner
 * field was disabled for them but still dehydrated, so a crafted request
 * handed the task to anybody.
 */
class CrmTaskOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_that_cannot_assign_always_owns_its_own_follow_up(): void
    {
        $me = User::factory()->create()->assignRole(Access::BOOKING_STAFF);
        $somebodyElse = User::factory()->create()->assignRole(Access::BOOKING_STAFF);
        $this->assertFalse($me->can('task.assign'));

        Livewire::actingAs($me)
            ->test(ListCrmTasks::class)
            ->callAction('create', data: [
                'subject' => 'Ring them back',
                'due_on' => now()->addDay()->toDateString(),
                'owner_id' => $somebodyElse->getKey(),
            ]);

        $this->assertSame($me->getKey(), CrmTask::sole()->owner_id);
    }
}
