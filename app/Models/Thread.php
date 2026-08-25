<?php

declare(strict_types=1);

namespace App\Models;

use Clutch\Laravel\Models\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chat thread, which is one Clutch session.
 *
 * The mapping is the whole trick: a session already carries the conversation,
 * so a thread is little more than a title and a pointer to it.
 */
class Thread extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
