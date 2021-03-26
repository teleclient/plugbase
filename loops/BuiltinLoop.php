<?php

declare(strict_types=1);

use danog\MadelineProto\Loop\GenericLoop;
use danog\MadelineProto\Logger;

class BuiltinLoop extends AbstractLoop implements Loop
{
    public $loop;

    public function onStart(): \Generator
    {
        $eh = $this->eh;
        $mp = $eh;
        $userDate = $this->userDate;
        $this->loop = new GenericLoop(
            $mp,
            function () use ($eh, $userDate) {
                $eventHandler = $this->eh->getEventHandler();
                $now = $userDate->milli();
                if ($eh->getLoopState() && $now % 60 === 0) {
                    $msg = 'Time is ' . $now . '!';
                    yield $eh->logger($msg, Logger::ERROR);
                    if (false) {
                        yield $eh->account->updateProfile([
                            'about' => $now
                        ]);
                    }
                    if (false) {
                        $robotId = $eventHandler->getRobotID();
                        yield $eh->messages->sendMessage([
                            'peer'    => $robotId,
                            'message' => $msg
                        ]);
                    }
                }
                yield $eh->sleep(1);
                $delay = \secondsToNexMinute();
                return $delay; // Repeat at the very begining of the next minute, sharp.
            },
            'Repeating Loop'
        );
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
