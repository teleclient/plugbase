<?php

declare(strict_types=1);

class YourPlugin
{
    private BaseEventHandler $eh;

    function __construct(BaseEventHandler $eh)
    {
        $this->eh = $eh;
    }

    public function onStart(): \Generator
    {
        return;
        yield;
    }

    public function handleEvent(array $update): \Generator
    {
        return;
        yield;
    }
}
