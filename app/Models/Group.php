<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ajo (esusu) savings group. Every member pays `amount` each cycle, and the
 * member at position i collects the whole pot in cycle i.
 */
#[Table('ajo_groups')]
#[Fillable(['id', 'user_id', 'name', 'amount', 'frequency', 'start_date', 'client_updated_at'])]
class Group extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'start_date' => 'immutable_date',
            'client_updated_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'group_id')->orderBy('position');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class, 'group_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'group_id');
    }

    /** One cycle per member. */
    public function cycleCount(): int
    {
        return $this->members->count();
    }

    public function pot(): int
    {
        return $this->amount * $this->cycleCount();
    }

    /**
     * When a cycle is due. Weekly: every 7 days from the start. Monthly: the same
     * day each month, clamped to the 28th so every month has it (matches the app).
     */
    public function dueDate(int $cycle): CarbonImmutable
    {
        $start = CarbonImmutable::parse($this->start_date)->startOfDay();

        if ($this->frequency === 'weekly') {
            return $start->addDays(7 * $cycle);
        }

        return $start->startOfMonth()->addMonthsNoOverflow($cycle)->day(min($start->day, 28));
    }
}
