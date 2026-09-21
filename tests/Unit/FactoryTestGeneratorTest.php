<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeModelWithoutFactory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use PHPUnit\Framework\TestCase;

class FactoryTestGeneratorTest extends TestCase
{
    public function test_it_generates_factory_code_when_the_factory_exists(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate(
            $this->attributeScenario(FakePost::class)
        );

        $this->assertSame([
            'generated' => true,
            'code' => implode("\n", [
                '$post = \\' . FakePost::class . '::factory()->create([',
                "    'title' => null,",
                ']);',
            ]),
        ], $result);
    }

    public function test_it_returns_a_message_when_the_factory_does_not_exist(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate(
            $this->attributeScenario(FakeModelWithoutFactory::class)
        );

        $this->assertSame([
            'generated' => false,
            'message' => sprintf(
                'Factory for model %s does not exist; the test could not be generated.',
                FakeModelWithoutFactory::class
            ),
        ], $result);
    }

    private function attributeScenario(string $modelClass): array
    {
        return [
            'root' => 'post',
            'rootClass' => $modelClass,
            'rootType' => 'object',
            'path' => ['title'],
            'target' => [
                'model' => $modelClass,
                'property' => 'title',
                'kind' => 'attribute',
            ],
            'strategy' => 'null_attribute',
        ];
    }
}
