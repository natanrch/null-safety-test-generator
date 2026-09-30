<?php

namespace Natan\NullSafetyTestGenerator\Resolvers;

class EloquentAccessChainResolver
{
    public function __construct(
        private EloquentRelationshipResolver $relationshipResolver,
        private ?EloquentAttributeTypeResolver $attributeTypeResolver = null
    ) {
        $this->attributeTypeResolver ??= new EloquentAttributeTypeResolver();
    }

    public function resolve(
        string $modelClass,
        array $accesses
    ): array 
    {
        $resolvedAccesses = [];
        $currentModelClass = $modelClass;

        foreach ($accesses as $access) {
            if (
                ($access['type'] ?? null) !== 'property'
                || ! isset($access['name'])
                || ! is_string($access['name'])
            ) {
                continue;
            }

            $property = $access['name'];
            $relationship = $this->relationshipResolver->resolve(
                $currentModelClass,
                $property
            );

            if ($relationship !== null) {
                $resolvedAccesses[] = $relationship;

                if (! is_string($relationship['relatedClass'] ?? null)) {
                    break;
                }

                $currentModelClass = $relationship['relatedClass'];

                continue;
            }

            $objectAttribute = $this->attributeTypeResolver->resolve(
                $currentModelClass,
                $property
            );

            if ($objectAttribute !== null) {
                $resolvedAccesses[] = $objectAttribute;
                $currentModelClass = $objectAttribute['valueClass'];

                continue;
            }

            $resolvedAccesses[] = [
                'model' => $currentModelClass,
                'property' => $property,
                'kind' => 'attribute',
            ];
        }

        return $resolvedAccesses;
    }
}
