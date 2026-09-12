<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\AnotherFakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithAdditionalObjectMethods;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithChainedMethods;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithCollections;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithPluckedCollection;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeObject;
use PHPUnit\Framework\TestCase;

class ControllerMethodAnalyzerTest extends TestCase
{
    public function test_it_identifies_object_passed_as_parameter(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeController::class,
            'show'
        );

        $this->assertArrayHasKey('object', $result);

        $this->assertSame(
            [
                'class' => FakeObject::class,
                'type' => 'object',
            ],
            $result['object']
        );
    }

    public function test_it_identifies_object_loaded_inside_method(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeController::class,
            'show'
        );

        $this->assertArrayHasKey('otherObject', $result);

        $this->assertSame(
            [
                'class' => AnotherFakeObject::class,
                'type' => 'object',
            ],
            $result['otherObject']
        );
    }

    public function test_it_identifies_parameter_and_locally_loaded_object_classes(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeController::class,
            'show'
        );

        $this->assertSame([
            'object' => [
                'class' => FakeObject::class,
                'type' => 'object',
            ],
            'otherObject' => [
                'class' => AnotherFakeObject::class,
                'type' => 'object',
            ],
        ], $result);
    }

    public function test_it_identifies_objects_from_chained_static_and_instance_calls(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeControllerWithChainedMethods::class,
            'show'
        );

        $this->assertSame([
            'object' => [
                'class' => FakeObject::class,
                'type' => 'object',
            ],
            'firstObject' => [
                'class' => AnotherFakeObject::class,
                'type' => 'object',
            ],
            'secondObject' => [
                'class' => AnotherFakeObject::class,
                'type' => 'object',
            ],
        ], $result);
    }

    public function test_it_differentiates_objects_from_collections(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeControllerWithCollections::class,
            'show'
        );

        $this->assertSame([
            'object' => [
                'class' => FakeObject::class,
                'type' => 'object',
            ],

            'firstObject' => [
                'class' => AnotherFakeObject::class,
                'type' => 'object',
            ],

            'objects' => [
                'class' => AnotherFakeObject::class,
                'type' => 'collection',
            ],

            'allObjects' => [
                'class' => AnotherFakeObject::class,
                'type' => 'collection',
            ],
        ], $result);
    }

    public function test_it_identifies_additional_methods_that_return_objects(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeControllerWithAdditionalObjectMethods::class,
            'show'
        );

        $objectMetadata = [
            'class' => AnotherFakeObject::class,
            'type' => 'object',
        ];

        $this->assertSame([
            'foundObject' => $objectMetadata,
            'firstObject' => $objectMetadata,
            'soleObject' => $objectMetadata,
            'createdObject' => $objectMetadata,
            'firstOrCreatedObject' => $objectMetadata,
            'firstOrNewObject' => $objectMetadata,
        ], $result);
    }

    public function test_it_identifies_collection_returned_by_pluck(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeControllerWithPluckedCollection::class,
            'show'
        );

        $this->assertSame([
            'values' => [
                'class' => AnotherFakeObject::class,
                'type' => 'collection',
            ],
        ], $result);
    }
}
