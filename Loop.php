<?php

declare(strict_types=1);

interface Loop
{
    function __invoke(): \Generator;
}
