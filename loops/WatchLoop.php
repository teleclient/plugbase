<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProto;

// The watch loop-plugin configuration:
//     'loops' => [..., 'Watch', ...]; // in config.php
//
// Commands:
//     This loop can not be turned off

class WatchLoop extends AbstractLoop implements Loop
{
    public function onStart(): \Generator
    {
        $this->logger("Builtin Loop onStart invoked!", Logger::ERROR);
        return;
        yield;
    }

    protected function task(string $loopState): \Generator
    {
        //$this->eh->logger("'watch' loop plug invoked!", Logger::ERROR);
        //return 30;
        $state = authorizationState($this->eh);
        if (!$this->eh->hasAllAuth() || $state !== MTProto::LOGGED_IN) {
            yield $this->eh->notAuthorized();
        }
        return 10;
        yield;
    }
}
