<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deal extends Model
{
    /** The pipeline, in order. */
    public const STAGES = ['discovery', 'demo', 'proposal', 'negotiation', 'won', 'lost'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_touched_at' => 'datetime'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('id');
    }

    public function value(): string
    {
        return '$'.number_format($this->value_cents / 100);
    }

    /**
     * What the deal is worth after any approved discount.
     */
    public function netValue(): string
    {
        $net = $this->value_cents * (1 - ($this->discount_percent ?? 0) / 100);

        return '$'.number_format($net / 100);
    }

    public function isStale(): bool
    {
        return $this->last_touched_at !== null
            && $this->last_touched_at->lt(now()->subDays(14))
            && ! in_array($this->stage, ['won', 'lost'], true);
    }
}
