<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** A cycle's pot handed to its recipient. */
#[Table('ajo_payouts')]
#[Fillable(['group_id', 'cycle', 'collected_at'])]
class Payout extends Model
{
    protected function casts(): array
    {
        return ['cycle' => 'integer', 'collected_at' => 'immutable_datetime'];
    }
}
