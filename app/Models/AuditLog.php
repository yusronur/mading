<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToSchool, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'actor_id', 'action', 'target_type', 'target_id', 'meta', 'ip'];

    protected $casts = ['meta' => 'array'];

    public static function record(string $action, ?Model $target = null, array $meta = []): self
    {
        return static::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'meta' => $meta,
            'ip' => request()?->ip(),
        ]);
    }
}
