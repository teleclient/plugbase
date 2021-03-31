<?php

declare(strict_types=1);

interface Loop
{
    public function onStart(): \Generator;
    public function __invoke(array $update, array $vars): \Generator;
}
