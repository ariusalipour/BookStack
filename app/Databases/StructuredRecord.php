<?php

namespace BookStack\Databases;

use BookStack\App\Model;
use BookStack\Entities\Models\Page;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $database_id
 * @property int $page_id
 * @property array $values
 * @property int $version
 * @property Page $page
 * @property StructuredDatabase $database
 */
class StructuredRecord extends Model
{
    use HasUuids;

    protected $table = 'structured_records';
    protected $guarded = [];
    protected $casts = ['values' => 'array', 'version' => 'integer', 'page_id' => 'integer'];

    /** @return BelongsTo<StructuredDatabase, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(StructuredDatabase::class, 'database_id');
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return HasMany<StructuredRelation, $this> */
    public function relations(): HasMany
    {
        return $this->hasMany(StructuredRelation::class, 'source_record_id');
    }
}
