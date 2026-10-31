<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisibleSerializedPost extends Post
{
    protected $appends = [
        'author_label',
        'category_label',
    ];

    protected $visible = [
        'id',
        'category_label',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return $this->category->name;
    }
}
