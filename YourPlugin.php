<?php

declare(strict_types=1);

class YourPlugin
{
    private object $eh;

    function __construct(EventHandler $eh)
    {
        $this->eh = $eh;
    }

    public function onStart(): \Generator
    {
        return;
        yield;
    }

    public function __invoke(array $update): \Generator
    {
        return;
        yield;
    }
}
