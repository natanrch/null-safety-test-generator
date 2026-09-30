<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\ValueObjects\FakeAddress;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\ValueObjects\FakeSummary;

class TypedPost extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function getAddressAttribute(): FakeAddress
    {
        return new FakeAddress();
    }

    protected function summary(): Attribute
    {
        return Attribute::make(
            get: fn (): FakeSummary => new FakeSummary()
        );
    }
}
