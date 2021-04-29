<?php

declare(strict_types=1);

use danog\MadelineProto\MTProto;

// The watch loop-plugin configuration:
//     'loops' => [..., 'Watch', ...]; // in config.php
//
// Commands:
//     This loop can not be turned off

class WatchLoop extends AbstractLoop implements Loop
{
    protected function pluggedLoop(string $loopState): \Generator
    {
        $state = authorizationState($this->eh);
        if ($this->eh->hasAllAuth() || $state === MTProto::NOT_LOGGED_IN) {
            $this->eh->notLoggedIn();
        }
        return 5;
        yield;
    }
}
