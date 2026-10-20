<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalApplicationSecret extends Model
{
    protected $fillable = [
        'project_id',
        'key_id',
        'application_name',
        'secret_name',
        'secret_arn',
    ];
}
