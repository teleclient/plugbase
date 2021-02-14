<?php

declare(strict_types=1);

class YourPlugin extends AbstractPlugin implements Plugin
{
    private BaseEventHandler $eh;

    function __construct(BaseEventHandler $eh)
    {
        $this->eh = $eh;
    }

    public function onStart(BaseEventHandler $eh): \Generator
    {
        return;
        yield;
    }

    public function __invoke(array $update, array $vars, BaseEventHandler $eh): \Generator
    {
        // Handle $update.  Use $vars if necessary
        // .....

        return false; // return true if $update is handled.
        yield;
    }
}
