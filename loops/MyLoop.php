<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;

class MyLoop extends AbstractLoop implements Loop
{
    public function onStart(): \Generator
    {
        yield $this->eh->sleep(0); // Not really needed
    }

    protected function pluggedLoop(string $loopState): \Generator
    {
        if ($loopState === 'on') {
            $time  = $this->userDate->format();
            $entry = "The '{$this}' loop plugin: Time is $time!";
            $this->logger($entry, Logger::ERROR);
            yield $this->eh->sleep(1); // Not really needed.
        }
        return 60;
    }

    public function initialStae(): string
    {
        return 'on';
    }
}

// The plugin configuration:
//   'loops' => [..., 'My', ...];
//
// The Commands for this loop plugin:
// > loop my on
// > loop my off
// > loop my state