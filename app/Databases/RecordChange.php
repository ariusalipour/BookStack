<?php

namespace BookStack\Databases;

use BookStack\App\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use LogicException;

/**
 * Internal immutable snapshots; never serialize without checking current permissions.
 * Recycle-bin records retain history. Permanent record/database deletion purges it
 * through foreign-key cascades, matching native page revision retention.
 */
class RecordChange extends Model
{
    use HasUuids;

    protected $table = 'structured_record_changes';
    protected $guarded = [];
    protected $casts = ['snapshot' => 'array'];
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Record history is immutable.'));
        static::deleting(fn () => throw new LogicException('Record history is immutable.'));
    }
}
