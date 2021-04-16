<?php

declare(strict_types=1);

abstract class AbstractHandler
{
    function __construct(BaseEventHandler $eh)
    {
    }
    public function __destruct()
    {
    }
}
