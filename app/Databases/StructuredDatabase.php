<?php

namespace BookStack\Databases;

use BookStack\App\Model;
use BookStack\Entities\Models\Book;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $book_id
 * @property string $name
 * @property int $version
 * @property Book $book
 * @property \Illuminate\Database\Eloquent\Collection<int, StructuredField> $fields
 */
class StructuredDatabase extends Model
{
    use HasUuids;

    protected $table = 'structured_databases';
    protected $guarded = [];
    protected $casts = ['version' => 'integer', 'book_id' => 'integer'];

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return HasMany<StructuredField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(StructuredField::class, 'database_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<StructuredRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(StructuredRecord::class, 'database_id');
    }
}
