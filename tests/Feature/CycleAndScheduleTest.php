<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CycleAndScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(array $overrides = []): array
    {
        Sanctum::actingAs(User::factory()->create());
        $group = GroupSyncTest::appGroup(array_merge(['paid' => [], 'collected' => []], $overrides));
        $this->putJson("/api/groups/{$group['id']}", $group)->assertCreated();

        return $group;
    }

    public function test_marking_a_payment_is_idempotent_and_can_be_undone(): void
    {
        $g = $this->makeGroup();
        $url = "/api/groups/{$g['id']}/cycles/0/payments/m2";

        $this->putJson($url)->assertOk()->assertJsonPath('paid.0', ['m2']);
        $this->putJson($url)->assertOk()->assertJsonPath('paid.0', ['m2']);
        $this->assertDatabaseCount('ajo_contributions', 1);

        $this->deleteJson($url)->assertOk()->assertJsonPath('paid', []);
    }

    public function test_payments_reject_unknown_members_and_cycles(): void
    {
        $g = $this->makeGroup();

        $this->putJson("/api/groups/{$g['id']}/cycles/0/payments/nobody")->assertUnprocessable();
        $this->putJson("/api/groups/{$g['id']}/cycles/3/payments/m1")->assertUnprocessable();
    }

    public function test_marking_a_pot_collected(): void
    {
        $g = $this->makeGroup();

        $this->putJson("/api/groups/{$g['id']}/cycles/1/payout")->assertOk()->assertJsonPath('collected', [1]);
        $this->deleteJson("/api/groups/{$g['id']}/cycles/1/payout")->assertOk()->assertJsonPath('collected', []);
    }

    public function test_a_server_side_change_beats_an_older_phone_copy(): void
    {
        $g = $this->makeGroup(['updatedAt' => '2026-01-01T00:00:00Z']);
        $this->putJson("/api/groups/{$g['id']}/cycles/0/payments/m1")->assertOk();

        // The phone was offline and still has the January copy.
        $this->putJson("/api/groups/{$g['id']}", GroupSyncTest::appGroup(['paid' => [], 'collected' => [], 'updatedAt' => '2026-01-01T00:00:00Z']))
            ->assertStatus(409)
            ->assertJsonPath('paid.0', ['m1']);
    }

    public function test_monthly_schedule_clamps_to_the_28th_and_follows_payout_order(): void
    {
        $g = $this->makeGroup(['paid' => ['0' => ['m1', 'm2', 'm3'], '1' => ['m1']], 'collected' => [0]]);

        $res = $this->getJson("/api/groups/{$g['id']}/schedule?at=2026-03-01")->assertOk();

        $res->assertJsonPath('pot', 15000)
            ->assertJsonPath('currentCycle', 1)
            ->assertJsonPath('finished', false)
            ->assertJsonPath('cycles.0.dueDate', '2026-01-28')
            ->assertJsonPath('cycles.1.dueDate', '2026-02-28')
            ->assertJsonPath('cycles.2.dueDate', '2026-03-28')
            ->assertJsonPath('cycles.0.recipient.name', 'Bisi')
            ->assertJsonPath('cycles.2.recipient.name', 'Dayo')
            ->assertJsonPath('cycles.0.complete', true)
            ->assertJsonPath('cycles.0.collected', true)
            ->assertJsonPath('cycles.1.paidCount', 1)
            ->assertJsonPath('cycles.1.collectedSoFar', 5000)
            ->assertJsonPath('cycles.1.owing.0.name', 'Chika')
            ->assertJsonCount(2, 'cycles.1.owing');
    }

    public function test_weekly_schedule_and_before_the_start(): void
    {
        $g = $this->makeGroup(['frequency' => 'weekly', 'start' => '2026-12-07T00:00:00.000']);

        $this->getJson("/api/groups/{$g['id']}/schedule?at=2026-12-01")
            ->assertOk()
            ->assertJsonPath('currentCycle', -1)
            ->assertJsonPath('cycles.0.dueDate', '2026-12-07')
            ->assertJsonPath('cycles.1.dueDate', '2026-12-14')
            ->assertJsonPath('cycles.2.dueDate', '2026-12-21');

        $this->getJson("/api/groups/{$g['id']}/schedule?at=2027-06-01")->assertJsonPath('currentCycle', 2); // clamped to the last cycle
    }
}
