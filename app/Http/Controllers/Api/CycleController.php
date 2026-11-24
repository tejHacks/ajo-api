<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Mark one contribution paid or unpaid, and one pot collected or not.
 * Each call is idempotent and returns the updated group.
 */
class CycleController extends Controller
{
    public function markPaid(Request $request, string $id, int $cycle, string $member): GroupResource
    {
        $group = $this->cycleOf($request, $id, $cycle);
        abort_unless($group->members->contains('member_key', $member), 422, 'That member is not in this group.');

        $group->contributions()->firstOrCreate(
            ['cycle' => $cycle, 'member_key' => $member],
            ['paid_at' => CarbonImmutable::now()],
        );

        return $this->touched($group);
    }

    public function unmarkPaid(Request $request, string $id, int $cycle, string $member): GroupResource
    {
        $group = $this->cycleOf($request, $id, $cycle);
        $group->contributions()->where(['cycle' => $cycle, 'member_key' => $member])->delete();

        return $this->touched($group);
    }

    public function markCollected(Request $request, string $id, int $cycle): GroupResource
    {
        $group = $this->cycleOf($request, $id, $cycle);
        $group->payouts()->firstOrCreate(['cycle' => $cycle], ['collected_at' => CarbonImmutable::now()]);

        return $this->touched($group);
    }

    public function unmarkCollected(Request $request, string $id, int $cycle): GroupResource
    {
        $group = $this->cycleOf($request, $id, $cycle);
        $group->payouts()->where('cycle', $cycle)->delete();

        return $this->touched($group);
    }

    private function cycleOf(Request $request, string $id, int $cycle): Group
    {
        $group = $request->user()->groups()->with('members')->findOrFail($id);
        abort_if($cycle < 0 || $cycle >= $group->members->count(), 422, "Cycle {$cycle} is outside this group's round.");

        return $group;
    }

    /** A change on the server is the newest version of the group. */
    private function touched(Group $group): GroupResource
    {
        $group->forceFill(['client_updated_at' => CarbonImmutable::now()])->save();

        return new GroupResource($group->fresh(['members', 'contributions', 'payouts']));
    }
}
