<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    protected $fillable = [
        'owner_user_id',
        'name',
        'description',
        'metadata',
        'nexus_project_id',
        'nexus_api_key_id',
        'pending_revoke_key_id',
        'api_key',
    ];

    protected $hidden = [
        'api_key',
        'nexus_project_id',
        'nexus_api_key_id',
        'pending_revoke_key_id',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'metadata' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Admin) {
            return $query;
        }

        return $query->where('owner_user_id', $user->id);
    }
}
