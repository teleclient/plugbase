<?php

declare(strict_types=1);

class BuiltinPlugin
{
    private EventHandler $eh;

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
        switch ($update['_']) {
            case 'updateNewChannelMessage':
            case 'updateReadChannelInbox':
            case 'updateEditMessage':
                break;
            default:
                return;
        }
        yield $this->eh->echo($update['_'] . PHP_EOL);
    }
}
