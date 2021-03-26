<?php

declare(strict_types=1);

use danog\MadelineProto\API;

abstract class AbstractLoop
{
    protected API              $mp;
    protected BaseEventHandler $eh;
    protected array    $robotConfig;
    protected UserDate $userDate;
    protected string   $loopName;

    function __construct(API $mp, BaseEventHandler $eh)
    {
        $this->mp = $mp;
        $this->eh = $eh;
        $this->robotConfig = $GLOBALS['robotConfig'];
        $this->userDate = new \UserDate($robotConfig['zone'] ?? 'America/Los_Angeles');
        $className = get_class();
        $this->loopName = substr($className, 0, strlen($className) - 4);
    }
}
