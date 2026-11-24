<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** A member's payment for one cycle. */
#[Table('ajo_contributions')]
#[Fillable(['group_id', 'cycle', 'member_key', 'paid_at'])]
class Contribution extends Model
{
    protected function casts(): array
    {
        return ['cycle' => 'integer', 'paid_at' => 'immutable_datetime'];
    }
}
