<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;

class BuiltinLoop extends AbstractLoop implements Loop
{
    public function onStart(): \Generator
    {
        yield $this->logger("Builtin Loop onStart invoked!", Logger::ERROR);
    }

    protected function pluggedLoop(bool $loopState): \Generator
    {
        if (false && $loopState) {
            yield $this->eh->account->updateProfile([
                'about' => $this->userDate->format()
            ]);
        }
        if (false && $loopState) {
            $robotId = $this->eh->getRobotID();
            yield $this->eh->messages->sendMessage([
                'peer'    => $this->eh->getRobotID(),
                'message' => "{$this} loop plugin: Time is " . $this->userDate->format() . "!"
            ]);
        }
        yield $this->eh->sleep(1);
        $delay = $this->secondsToNexMinute();
        if ($loopState) {
            $this->logger("The {$this} loop plugin's next invocation is in $delay seconds!", Logger::ERROR);
        }
        return $delay; // Repeat at the very begining of the next minute, sharp.
    }

    public function __destruct()
    {
        Logger::log("Destructing BuiltinLoop!", Logger::ERROR);
    }
}
