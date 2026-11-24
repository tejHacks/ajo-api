<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a whole group in the same JSON shape the Ajo app keeps on the phone:
 *
 *   { id, name, amount, frequency, start, members: [{id, name, phone}],
 *     paid: { "<cycle>": [memberId, ...] }, collected: [cycle, ...], updatedAt }
 *
 * Last write wins: if the server already has a newer copy than the one sent
 * (by `updatedAt`), nothing is saved and the caller gets the server copy back.
 */
class GroupSync
{
    /**
     * @return array{0: Group, 1: bool} the group, and whether the incoming copy was applied
     */
    public function save(User $user, string $id, array $data): array
    {
        $members = $data['members'];
        $memberIds = array_column($members, 'id');
        $cycles = count($members);

        $this->checkPaid($data['paid'] ?? [], $memberIds, $cycles);
        $this->checkCollected($data['collected'] ?? [], $cycles);

        $incomingAt = isset($data['updatedAt']) ? CarbonImmutable::parse($data['updatedAt']) : CarbonImmutable::now();

        return DB::transaction(function () use ($user, $id, $data, $members, $incomingAt) {
            $group = Group::query()->lockForUpdate()->find($id);

            if ($group && $group->user_id !== $user->id) {
                // Never reveal or overwrite another user's group.
                abort(404);
            }

            if ($group && $group->client_updated_at && $group->client_updated_at->greaterThan($incomingAt)) {
                return [$group->load('members', 'contributions', 'payouts'), false];
            }

            $group ??= new Group(['id' => $id, 'user_id' => $user->id]);
            $group->fill([
                'name' => $data['name'],
                'amount' => $data['amount'],
                'frequency' => $data['frequency'],
                'start_date' => CarbonImmutable::parse($data['start'])->toDateString(),
                'client_updated_at' => $incomingAt,
            ])->save();

            // Members, contributions and payouts are replaced as a whole: the
            // phone's copy is the full truth for this version of the group.
            $group->members()->delete();
            foreach (array_values($members) as $position => $m) {
                $group->members()->create([
                    'member_key' => $m['id'],
                    'name' => $m['name'],
                    'phone' => $m['phone'] ?? '',
                    'position' => $position,
                ]);
            }

            $group->contributions()->delete();
            foreach ($data['paid'] ?? [] as $cycle => $ids) {
                foreach (array_unique($ids) as $memberKey) {
                    $group->contributions()->create(['cycle' => (int) $cycle, 'member_key' => $memberKey, 'paid_at' => $incomingAt]);
                }
            }

            $group->payouts()->delete();
            foreach (array_unique($data['collected'] ?? []) as $cycle) {
                $group->payouts()->create(['cycle' => (int) $cycle, 'collected_at' => $incomingAt]);
            }

            return [$group->load('members', 'contributions', 'payouts'), true];
        });
    }

    private function checkPaid(array $paid, array $memberIds, int $cycles): void
    {
        foreach ($paid as $cycle => $ids) {
            if (! is_numeric($cycle) || (int) $cycle < 0 || (int) $cycle >= $cycles) {
                throw ValidationException::withMessages(['paid' => "Cycle {$cycle} is outside this group's round."]);
            }
            foreach ($ids as $memberKey) {
                if (! in_array($memberKey, $memberIds, true)) {
                    throw ValidationException::withMessages(['paid' => "Member {$memberKey} is not in this group."]);
                }
            }
        }
    }

    private function checkCollected(array $collected, int $cycles): void
    {
        foreach ($collected as $cycle) {
            if ((int) $cycle < 0 || (int) $cycle >= $cycles) {
                throw ValidationException::withMessages(['collected' => "Cycle {$cycle} is outside this group's round."]);
            }
        }
    }
}
