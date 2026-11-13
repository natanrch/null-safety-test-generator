<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Resolvers\EloquentModelClassResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Item;
use PHPUnit\Framework\TestCase;

class EloquentModelClassResolverTest extends TestCase
{
    public function test_it_resolves_a_model_by_table_when_english_singularization_differs(): void
    {
        class_exists(Item::class);

        $this->assertSame(
            Item::class,
            (new EloquentModelClassResolver())->resolveTable('itens')
        );
    }
}
