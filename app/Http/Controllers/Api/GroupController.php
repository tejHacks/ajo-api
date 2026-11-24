<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Services\GroupSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's savings groups. */
class GroupController extends Controller
{
    /** A plain JSON list of the user's groups, each in the app's shape. */
    public function index(Request $request): JsonResponse
    {
        $groups = $request->user()->groups()
            ->with('members', 'contributions', 'payouts')
            ->orderBy('created_at')
            ->get();

        return response()->json(GroupResource::collection($groups)->resolve($request));
    }

    public function show(Request $request, string $id): GroupResource
    {
        return new GroupResource($this->owned($request, $id));
    }

    /**
     * Create or replace a whole group (used by the app to sync, including
     * groups made offline). Returns 409 with the server copy when the server
     * already has a newer version.
     */
    public function update(Request $request, string $id, GroupSync $sync): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'string', 'in:'.$id],
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'frequency' => ['required', 'in:weekly,monthly'],
            'start' => ['required', 'date'],
            'members' => ['required', 'array', 'min:1', 'max:100'],
            'members.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'members.*.name' => ['required', 'string', 'max:120'],
            'members.*.phone' => ['nullable', 'string', 'max:32'],
            'paid' => ['sometimes', 'array'],
            'paid.*' => ['array'],
            'paid.*.*' => ['string', 'max:64'],
            'collected' => ['sometimes', 'array'],
            'collected.*' => ['integer', 'min:0'],
            'updatedAt' => ['nullable', 'date'],
        ]);
        abort_if(strlen($id) > 64, 422, 'Group id is too long.');

        $existed = Group::whereKey($id)->exists();
        [$group, $applied] = $sync->save($request->user(), $id, $data);

        $status = ! $applied ? 409 : ($existed ? 200 : 201);

        return (new GroupResource($group))->response()->setStatusCode($status);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->owned($request, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Due dates, recipients and who still owes, for every cycle. */
    public function schedule(Request $request, string $id): JsonResponse
    {
        $group = $this->owned($request, $id);
        $now = $request->filled('at') ? CarbonImmutable::parse($request->query('at')) : CarbonImmutable::now();

        $paid = $group->contributions->groupBy('cycle')->map(fn ($c) => $c->pluck('member_key')->all());
        $collected = $group->payouts->pluck('cycle')->all();
        $members = $group->members->values();
        $cycles = $members->count();

        $rows = [];
        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            $paidIds = $paid[$cycle] ?? [];
            $recipient = $members[$cycle];
            $rows[] = [
                'cycle' => $cycle,
                'dueDate' => $group->dueDate($cycle)->toDateString(),
                'recipient' => ['id' => $recipient->member_key, 'name' => $recipient->name],
                'paidCount' => count($paidIds),
                'collectedSoFar' => count($paidIds) * $group->amount,
                'complete' => count($paidIds) === $cycles,
                'collected' => in_array($cycle, $collected, true),
                'owing' => $members->reject(fn ($m) => in_array($m->member_key, $paidIds, true))
                    ->map(fn ($m) => ['id' => $m->member_key, 'name' => $m->name])->values(),
            ];
        }

        return response()->json([
            'groupId' => $group->id,
            'pot' => $group->pot(),
            'currentCycle' => $this->currentCycle($group, $now),
            'finished' => count(array_unique($collected)) === $cycles && $cycles > 0,
            'cycles' => $rows,
        ]);
    }

    /** The cycle we're in at `$now` (clamped to the round), or -1 before it starts. Matches the app. */
    private function currentCycle(Group $group, CarbonImmutable $now): int
    {
        $cycles = $group->cycleCount();
        if ($cycles === 0 || $now->lessThan($group->dueDate(0))) {
            return -1;
        }
        $c = 0;
        while ($c < $cycles - 1 && ! $now->lessThan($group->dueDate($c + 1))) {
            $c++;
        }

        return $c;
    }

    private function owned(Request $request, string $id): Group
    {
        return $request->user()->groups()->with('members', 'contributions', 'payouts')->findOrFail($id);
    }
}
