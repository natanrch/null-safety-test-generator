<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use PHPUnit\Framework\TestCase;

class FeatureTestFileGeneratorTest extends TestCase
{
    public function test_it_generates_a_complete_feature_test_file(): void
    {
        $generator = new FeatureTestFileGenerator();

        $testMethod = sprintf(
            <<<'PHP'
public function test_posts_show_does_not_fail_when_post_author_is_null(): void
{
    $post = \%s::factory()->state(['author_id' => null])->create();

    $response = $this->get(
        route('posts.show', ['post' => $post])
    );

    $this->assertLessThan(500, $response->status());
}
PHP,
            FakePost::class
        );

        $result = $generator->generate(
            'PostsShowNullSafetyTest',
            [$testMethod]
        );

        $expectedCode = sprintf(
            <<<'PHP'
<?php

namespace Tests\Feature\Generated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostsShowNullSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_show_does_not_fail_when_post_author_is_null(): void
    {
        $post = \%s::factory()->state(['author_id' => null])->create();

        $response = $this->get(
            route('posts.show', ['post' => $post])
        );

        $this->assertLessThan(500, $response->status());
    }
}
PHP,
            FakePost::class
        );

        $this->assertSame([
            'generated' => true,
            'fileName' => 'PostsShowNullSafetyTest.php',
            'code' => $expectedCode,
        ], $result);
    }
}
