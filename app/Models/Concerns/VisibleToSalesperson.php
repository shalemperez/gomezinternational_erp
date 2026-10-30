<?php

namespace App\Models\Concerns;

use App\Models\Salesperson;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait VisibleToSalesperson
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(static::VIEW_ALL_PERMISSION)) {
            return $query;
        }

        return $query->where($query->getModel()->getTable().'.salesperson_id', $user->salesperson instanceof Salesperson ? $user->salesperson->id : -1);
    }
}
