<?php

namespace BookStack\Databases;

use BookStack\App\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $database_id
 * @property string $name
 * @property string $type
 * @property array $config
 * @property int $position
 */
class StructuredField extends Model
{
    use HasUuids;

    public const TYPES = ['text', 'number', 'checkbox', 'date', 'select', 'multi_select', 'url', 'email', 'relation', 'rollup'];

    protected $table = 'structured_fields';
    protected $guarded = [];
    protected $casts = ['config' => 'array', 'position' => 'integer'];

    /** @return BelongsTo<StructuredDatabase, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(StructuredDatabase::class, 'database_id');
    }
}
