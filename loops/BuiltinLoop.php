<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;

class BuiltinLoop extends AbstractLoop implements Loop
{
    protected function pluggedLoop(): \Generator
    {
        $eh  = $this->eh->getEventHandler();
        $now =  $this->userDate->format();
        $msg = "{$this}: Time is $now!";
        yield $eh->logger($msg, Logger::ERROR);
        if (false) {
            yield $eh->account->updateProfile([
                'about' => $now
            ]);
        }
        if (true) {
            $robotId = $eh->getRobotID();
            yield $eh->messages->sendMessage([
                'peer'    => $robotId,
                'message' => $msg
            ]);
        }
        yield $eh->sleep(1);
        $delay = \secondsToNexMinute();
        return $delay; // Repeat at the very begining of the next minute, sharp.
    }
}
