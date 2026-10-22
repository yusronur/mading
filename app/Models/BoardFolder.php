<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BoardFolder extends Model
{
    use BelongsToSchool, HasUuids;

    protected $fillable = ['school_id', 'owner_id', 'name'];
}
