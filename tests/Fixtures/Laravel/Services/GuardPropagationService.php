<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class GuardPropagationService
{
    public function name(Post $record)
    {
        return $this->readName($record);
    }

    private function readName(Post $renamed)
    {
        return $renamed->author->name;
    }

    public function profile(Post $record)
    {
        return $record->author->profile->name;
    }

    public function related(Author $person)
    {
        return $person->profile->name;
    }

    public function mutate(Post $record): void
    {
        $record->author = null;
    }

    public function mutateThenRead(Post $record)
    {
        $this->mutate($record);
        return $this->name($record);
    }
}
