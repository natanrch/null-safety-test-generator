<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;

class ModelPropagationAnalyzer extends WriteControllerAccessAnalyzer
{
    public function __construct(
        EloquentAccessChainResolver $accessChainResolver,
        bool $includeEntryMethodAccesses = false
    ) {
        parent::__construct(
            $accessChainResolver,
            $includeEntryMethodAccesses
        );
    }
}
