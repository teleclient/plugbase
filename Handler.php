<?php

declare(strict_types=1);

interface Handler
{
    function __invoke(array $update, array $vars, BaseEventHandler $eh): \Generator;
}
