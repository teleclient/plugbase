<?php

declare(strict_types=1);

define("SCRIPT_START_TIME", \microtime(true));

require 'utils/functions.php';
includeMadeline('composer');

//require 'BaseEventHandler.php';
require 'Robot.php';

$robot = new Robot();
$robot->start();

\error_clear_last();
echo ('Bye, bye!<br>' . PHP_EOL);
error_log('Bye, bye!');
