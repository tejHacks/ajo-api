<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member of a group. `position` is their place in the payout order. */
#[Table('ajo_members')]
#[Fillable(['group_id', 'member_key', 'name', 'phone', 'position'])]
class Member extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
