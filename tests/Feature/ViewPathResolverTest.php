<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class ViewPathResolverTest extends TestCase
{
    public function test_it_resolves_a_view_name_to_its_blade_file_path(): void
    {
        $resolver = new ViewPathResolver(
            $this->app->make('view.finder')
        );

        $result = $resolver->resolve('posts.show');

        $this->assertSame(
            realpath(
                __DIR__ . '/../Fixtures/Laravel/views/posts/show.blade.php'
            ),
            $result
        );
    }
}
