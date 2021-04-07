<?php

declare(strict_types=1);

//namespace danog\MadelineProto\Loop\Generic;

use danog\MadelineProto\API;
use danog\MadelineProto\Generic\GenericLoop;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Loop\Impl\ResumableSignalLoop;

abstract class AbstractLoop extends ResumableSignalLoop
{
    const STOP     = -1;
    const PAUSE    = null;
    const CONTINUE = 0;

    //protected $callback;
    //protected $name;

    protected API              $mp;
    protected BaseEventHandler $eh;
    protected array    $robotConfig;
    protected UserDate $userDate;
    protected string   $loopName;

    function __construct(API $mp, BaseEventHandler $eh)
    {
        parent::__construct($mp);
        $this->mp = $mp;
        $this->eh = $eh;
        $this->robotConfig = $GLOBALS['robotConfig'];
        $this->userDate = new \UserDate($robotConfig['zone'] ?? 'America/Los_Angeles');
        $className = get_class($this);
        $this->loopName = substr($className, 0, strlen($className) - 4);
        $mp->logger("AbstractLoop constructor: {className => '$className'}");
    }
    //public function __construct($API, $callback, $name)
    //{
    //    $this->API = $API;
    //    $this->callback = $callback->bindTo($this);
    //    $this->name = $name;
    //}

    public function onStart(): \Generator
    {
        $this->start();
        return;
        yield;
    }

    public function loop(): \Generator
    {
        $loopName = $this->__toString();
        $this->mp->logger("AbstractLoop loop: {className => '$loopName'}");
        //$callback = $this->callback;
        while (true) {
            $timeout = yield $this->pluggedLoop();
            if ($timeout === self::PAUSE) {
                $this->API->logger->logger("Pausing {$this}", Logger::ERROR);
            } elseif ($timeout > 0) {
                $this->API->logger->logger("Pausing {$this} for {$timeout}", Logger::ERROR);
            }
            if ($timeout === self::STOP || yield $this->waitSignal($this->pause($timeout))) {
                return;
            }
        }
    }

    public function __toString(): string
    {
        $className = get_class($this);
        $loopName = substr($className, 0, strlen($className) - 4);
        $this->mp->logger("AbstractLoop toString: {loopName => '$loopName'}");
        return $loopName;
    }

    public function __invoke(array $update, array $vars): \Generator
    {
        // Handle $update.  Use $vars if necessary
        // .....
        return false; // return true if $update is handled.
        yield;
    }

    /**
     * pluggedLoop.
     *
     * The return value of the pluggedLoop can be:
     *    A number           - the loop will be paused for the specified number of seconds
     *    GenericLoop::STOP  - The loop will stop
     *    GenericLoop::PAUSE - The loop will pause forever (or until the `resume` method is called on 
     *                         the loop object from outside the loop)
     *    GenericLoop::CONTINUE - Return this if you want to rerun the loop without waiting.
     *
     * @param \danog\MadelineProto\API $API      Instance of MadelineProto
     * @param callable                 $callback Callback to run
     * @param string                   $name     Fetcher name
     */
    abstract protected function pluggedLoop(): \Generator;
}
