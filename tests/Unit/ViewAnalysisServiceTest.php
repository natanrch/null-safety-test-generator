<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerViewAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Services\ViewAnalysisService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\AnotherFakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithView;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakePostControllerWithView;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithRequestInput;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeProfile;
use PHPUnit\Framework\TestCase;

class ViewAnalysisServiceTest extends TestCase
{
    public function test_it_combines_controller_variables_with_blade_accesses(): void
    {
        $analyzer = $this->createAnalyzer();

        $result = $analyzer->analyze(
            FakeControllerWithView::class,
            'show',
            __DIR__ . '/../Fixtures/views/objects/show.blade.php'
        );

        $this->assertSame([
            'view' => 'fixtures.objects.show',
            'accesses' => [
                [
                    'root' => 'object',
                    'class' => FakeObject::class,
                    'type' => 'object',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                    'resolvedAccesses' => [
                        [
                            'model' => FakeObject::class,
                            'property' => 'name',
                            'kind' => 'attribute',
                        ],
                    ],
                ],
                [
                    'root' => 'otherObject',
                    'class' => AnotherFakeObject::class,
                    'type' => 'object',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                    'resolvedAccesses' => [
                        [
                            'model' => AnotherFakeObject::class,
                            'property' => 'name',
                            'kind' => 'attribute',
                        ],
                    ],
                ],
                [
                    'root' => 'objects',
                    'class' => AnotherFakeObject::class,
                    'type' => 'collection',
                    'alias' => 'item',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                    'resolvedAccesses' => [
                        [
                            'model' => AnotherFakeObject::class,
                            'property' => 'name',
                            'kind' => 'attribute',
                        ],
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_resolves_eloquent_relationships_in_view_accesses(): void
    {
        $analyzer = $this->createAnalyzer();

        $result = $analyzer->analyze(
            FakePostControllerWithView::class,
            'show',
            __DIR__ . '/../Fixtures/views/posts/show.blade.php'
        );

        $this->assertSame([
            'view' => 'fixtures.posts.show',
            'accesses' => [
                [
                    'root' => 'post',
                    'class' => FakePost::class,
                    'type' => 'object',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'author',
                        ],
                        [
                            'type' => 'property',
                            'name' => 'profile',
                        ],
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                    'resolvedAccesses' => [
                        [
                            'model' => FakePost::class,
                            'property' => 'author',
                            'kind' => 'relationship',
                            'relation' => 'belongsTo',
                            'relatedClass' => FakeAuthor::class,
                        ],
                        [
                            'model' => FakeAuthor::class,
                            'property' => 'profile',
                            'kind' => 'relationship',
                            'relation' => 'hasOne',
                            'relatedClass' => FakeProfile::class,
                        ],
                        [
                            'model' => FakeProfile::class,
                            'property' => 'name',
                            'kind' => 'attribute',
                        ],
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_preserves_request_input_in_the_combined_analysis(): void
    {
        $result = $this->createAnalyzer()->analyze(
            FakeControllerWithRequestInput::class,
            'create',
            __DIR__ . '/../Fixtures/views/objects/request.blade.php'
        );

        $this->assertSame([
            'source' => 'request',
            'parameter' => 'object_id',
            'valueFrom' => 'model_key',
        ], $result['accesses'][0]['input']);
    }

    private function createAnalyzer(): ViewAnalysisService
    {
        return new ViewAnalysisService(
            new ControllerViewAnalyzer(
                new ControllerMethodAnalyzer()
            ),
            new BladeAnalyzer(),
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        );
    }
}
