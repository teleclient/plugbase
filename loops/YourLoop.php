<?php

declare(strict_types=1);

class YourLoop extends AbstractLoop implements Loop
{
    public function onStart(): \Generator
    {
        return;
        yield;
    }

    public function loop(): \Generator
    {
        return;
        yield;
    }

    protected function pluggedLoop(bool $state): \Generator
    {
        return;
        yield;
    }

    public function __invoke(array $update, array $vars): \Generator
    {
        // Handle $update.  Use $vars if necessary
        // .....

        return false; // return true if $update is handled.
        yield;
    }
}
