<?php

namespace Natan\NullSafetyTestGenerator\Resolvers;

use Illuminate\View\ViewFinderInterface;
use InvalidArgumentException;

class ViewPathResolver
{
    public function __construct(
        private ViewFinderInterface $viewFinder
    ) {
    }

    public function resolve(string $viewName): ?string
    {
        try {
            $path = $this->viewFinder->find($viewName);
        } catch (InvalidArgumentException) {
            return null;
        }

        $resolvedPath = realpath($path);

        if ($resolvedPath === false || ! is_file($resolvedPath)) {
            return null;
        }

        return $resolvedPath;
    }
}
