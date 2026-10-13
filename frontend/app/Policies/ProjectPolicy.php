<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $user->role === UserRole::Admin || $project->owner_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
