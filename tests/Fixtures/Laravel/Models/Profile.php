<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories\ProfileFactory;

class Profile extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): ProfileFactory
    {
        return ProfileFactory::new();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
