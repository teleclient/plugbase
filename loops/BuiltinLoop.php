<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;

class BuiltinLoop extends AbstractLoop implements Loop
{
    public function onStart(): \Generator
    {
        $this->logger("Builtin Loop onStart invoked!", Logger::ERROR);
        return;
        yield;
    }

    protected function task(string $loopState): \Generator
    {
        if ($loopState === 'on') {
            if (true) {
                $this->logger("The '{$this}' loop plugin: Time is " . $this->userDate->format() . "!", Logger::ERROR);
            }
            if (false) {
                yield $this->eh->account->updateProfile([
                    'about' => $this->userDate->format()
                ]);
            }
            if (false) {
                $robotId = $this->eh->getRobotID();
                yield $this->eh->messages->sendMessage([
                    'peer'    => $this->eh->getRobotID(),
                    'message' => "{$this} loop plugin: Time is " . $this->userDate->format() . "!"
                ]);
            }
        }
        yield $this->eh->sleep(1);
        $delay = $this->secondsToNexMinute();
        if ($loopState === 'on') {
            //$this->logger("The '{$this}' loop plugin's next invocation is in $delay seconds!", Logger::ERROR);
        }
        return $delay; // Repeat at the very begining of the next minute, sharp.
    }

    public function __destruct()
    {
        Logger::log("Destructing the 'builtin' loop plugin!", Logger::ERROR);
        parent::__destruct();
    }
}
