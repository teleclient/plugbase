<?php

declare(strict_types=1);

class BuiltinPlugin
{
    private BaseEventHandler $eh;

    function __construct(BaseEventHandler $eh)
    {
        $this->eh = $eh;
    }

    public function onStart(): \Generator
    {
        //yield $this->eh->echo("Hi!" . PHP_EOL);
        return;
        yield;
    }

    public function handleEvent(array $update): \Generator
    {
        switch ($update['_']) {
            case 'updateNewChannelMessage':
            case 'updateReadChannelInbox':
            case 'updateEditMessage':
                break;
            default:
                return;
        }
        //yield $this->eh->echo($update['_'] . PHP_EOL);
        return;
        yield;
    }
}
