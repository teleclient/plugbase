<?php

declare(strict_types=1);

interface Plugin
{
    function __invoke(array $update, array $vars, BaseEventHandler $eh): \Generator;
}
