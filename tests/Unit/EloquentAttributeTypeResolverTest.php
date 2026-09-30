<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Illuminate\Support\Carbon;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAttributeTypeResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\TypedPost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\ValueObjects\FakeAddress;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\ValueObjects\FakeCoordinates;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\ValueObjects\FakeSummary;
use PHPUnit\Framework\TestCase;

class EloquentAttributeTypeResolverTest extends TestCase
{
    public function test_it_resolves_an_object_cast(): void
    {
        $result = (new EloquentAttributeTypeResolver())->resolve(
            TypedPost::class,
            'published_at'
        );

        $this->assertSame(Carbon::class, $result['valueClass']);
        $this->assertSame('cast', $result['valueSource']);
    }

    public function test_it_resolves_a_legacy_accessor(): void
    {
        $result = (new EloquentAttributeTypeResolver())->resolve(
            TypedPost::class,
            'address'
        );

        $this->assertSame(FakeAddress::class, $result['valueClass']);
        $this->assertSame('accessor', $result['valueSource']);
    }

    public function test_it_resolves_a_modern_accessor(): void
    {
        $result = (new EloquentAttributeTypeResolver())->resolve(
            TypedPost::class,
            'summary'
        );

        $this->assertSame(FakeSummary::class, $result['valueClass']);
        $this->assertSame('accessor', $result['valueSource']);
    }

    public function test_it_resolves_a_nested_value_object_property(): void
    {
        $result = (new EloquentAttributeTypeResolver())->resolve(
            FakeAddress::class,
            'coordinates'
        );

        $this->assertSame(FakeCoordinates::class, $result['valueClass']);
        $this->assertSame('value_object', $result['valueSource']);
    }
}
