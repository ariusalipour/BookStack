<?php

namespace BookStack\Databases;

use BookStack\App\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StructuredRelation extends Model
{
    protected $table = 'structured_relations';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;

    /** @return BelongsTo<StructuredRecord, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(StructuredRecord::class, 'target_record_id');
    }
}
