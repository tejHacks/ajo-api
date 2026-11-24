<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupSyncTest extends TestCase
{
    use RefreshDatabase;

    /** A group exactly as the Ajo app's AjoGroup.toJson() writes it. */
    public static function appGroup(array $overrides = []): array
    {
        return array_merge([
            'id' => 'g-1700000000000',
            'name' => 'Market women',
            'amount' => 5000,
            'frequency' => 'monthly',
            'start' => '2026-01-31T00:00:00.000',
            'members' => [
                ['id' => 'm1', 'name' => 'Bisi', 'phone' => '08030000001'],
                ['id' => 'm2', 'name' => 'Chika', 'phone' => ''],
                ['id' => 'm3', 'name' => 'Dayo', 'phone' => '08030000003'],
            ],
            'paid' => ['0' => ['m1', 'm2'], '1' => ['m3']],
            'collected' => [0],
            'updatedAt' => '2026-11-24T10:00:00.000Z',
        ], $overrides);
    }

    public function test_the_app_can_save_a_group_and_get_the_same_shape_back(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $group = self::appGroup();

        $this->putJson("/api/groups/{$group['id']}", $group)
            ->assertCreated()
            ->assertJsonPath('name', 'Market women')
            ->assertJsonPath('members.0.id', 'm1')
            ->assertJsonPath('members.2.name', 'Dayo')
            ->assertJsonPath('paid.0', ['m1', 'm2'])
            ->assertJsonPath('paid.1', ['m3'])
            ->assertJsonPath('collected', [0])
            ->assertJsonPath('start', '2026-01-31T00:00:00.000');

        $this->getJson('/api/groups')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $group['id']);
    }

    public function test_saving_again_replaces_members_payments_and_payouts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $group = self::appGroup();
        $this->putJson("/api/groups/{$group['id']}", $group)->assertCreated();

        $changed = self::appGroup([
            'members' => [['id' => 'm3', 'name' => 'Dayo'], ['id' => 'm1', 'name' => 'Bisi']], // new payout order, Chika left
            'paid' => ['0' => ['m3']],
            'collected' => [],
            'updatedAt' => '2026-11-24T11:00:00.000Z',
        ]);

        $this->putJson("/api/groups/{$group['id']}", $changed)
            ->assertOk()
            ->assertJsonCount(2, 'members')
            ->assertJsonPath('members.0.id', 'm3')
            ->assertJsonPath('paid.0', ['m3'])
            ->assertJsonPath('collected', []);
    }

    public function test_an_older_copy_does_not_overwrite_a_newer_one(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $newer = self::appGroup(['name' => 'Newer name', 'updatedAt' => '2026-11-24T12:00:00.000Z']);
        $this->putJson("/api/groups/{$newer['id']}", $newer)->assertCreated();

        $older = self::appGroup(['name' => 'Older name', 'updatedAt' => '2026-11-24T09:00:00.000Z']);
        $this->putJson("/api/groups/{$older['id']}", $older)
            ->assertStatus(409)
            ->assertJsonPath('name', 'Newer name'); // the server copy comes back so the phone can take it
    }

    public function test_payments_must_name_real_members_and_cycles(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/groups/g1', self::appGroup(['id' => 'g1', 'paid' => ['0' => ['stranger']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('paid');

        $this->putJson('/api/groups/g1', self::appGroup(['id' => 'g1', 'paid' => ['7' => ['m1']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('paid');

        $this->putJson('/api/groups/g1', self::appGroup(['id' => 'g1', 'collected' => [3]]))
            ->assertUnprocessable()->assertJsonValidationErrors('collected');
    }

    public function test_a_group_needs_valid_basics(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/groups/g1', self::appGroup(['id' => 'g1', 'amount' => 0, 'frequency' => 'daily', 'members' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'frequency', 'members']);

        $this->putJson('/api/groups/g1', self::appGroup(['id' => 'g2']))
            ->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_users_cannot_see_or_change_each_others_groups(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $group = self::appGroup();
        $this->putJson("/api/groups/{$group['id']}", $group)->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/groups/{$group['id']}")->assertNotFound();
        $this->putJson("/api/groups/{$group['id']}", self::appGroup(['name' => 'Hijack', 'updatedAt' => '2030-01-01T00:00:00Z']))->assertNotFound();
        $this->deleteJson("/api/groups/{$group['id']}")->assertNotFound();
        $this->getJson('/api/groups')->assertOk()->assertJsonCount(0);

        Sanctum::actingAs($owner);
        $this->getJson("/api/groups/{$group['id']}")->assertOk()->assertJsonPath('name', 'Market women');
    }

    public function test_deleting_a_group_removes_its_records(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $group = self::appGroup();
        $this->putJson("/api/groups/{$group['id']}", $group)->assertCreated();

        $this->deleteJson("/api/groups/{$group['id']}")->assertOk();
        $this->getJson("/api/groups/{$group['id']}")->assertNotFound();
        $this->assertDatabaseCount('ajo_members', 0);
        $this->assertDatabaseCount('ajo_contributions', 0);
        $this->assertDatabaseCount('ajo_payouts', 0);
    }
}
