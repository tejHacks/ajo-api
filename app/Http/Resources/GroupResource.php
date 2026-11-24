<?php

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A group in exactly the JSON shape the Ajo app stores on the phone, so the app
 * can save what it gets back without any mapping.
 *
 * @mixin Group
 */
class GroupResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $paid = [];
        foreach ($this->contributions->sortBy(['cycle', 'member_key']) as $c) {
            $paid[(string) $c->cycle][] = $c->member_key;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => $this->amount,
            'frequency' => $this->frequency,
            'start' => $this->start_date->toDateString().'T00:00:00.000',
            'members' => $this->members->map(fn ($m) => [
                'id' => $m->member_key,
                'name' => $m->name,
                'phone' => $m->phone,
            ])->values(),
            'paid' => (object) $paid,
            'collected' => $this->payouts->pluck('cycle')->sort()->values(),
            'updatedAt' => ($this->client_updated_at ?? $this->updated_at)?->toIso8601ZuluString('millisecond'),
        ];
    }
}
