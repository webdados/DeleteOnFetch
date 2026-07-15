<?php

namespace Modules\DeleteOnFetch\Entities;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PendingDeletion extends Model
{
    const MAX_ATTEMPTS = 5;

    protected $table = 'deleteonfetch_pending_deletions';

    protected $fillable = ['mailbox_id', 'uid', 'folder', 'delete_after', 'attempts', 'last_error'];

    protected $dates = ['delete_after'];

    /**
     * Rows whose delay has elapsed and haven't exhausted their retry budget.
     */
    public function scopeDue($query)
    {
        return $query->where('delete_after', '<=', Carbon::now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }
}
