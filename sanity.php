<?php

declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Magic;
use danog\MadelineProto\Shutdown;

\date_default_timezone_set('UTC');
\ignore_user_abort(true);
\set_time_limit(0);
\error_reporting(E_ALL);                                 // always TRUE
ini_set('ignore_repeated_errors', '1');                 // always TRUE
ini_set('display_startup_errors', '1');
ini_set('display_errors',         '1');                 // FALSE only in production or real server
ini_set('default_charset',        'UTF-8');
ini_set('precision',              '18');
ini_set('log_errors',             '1');                 // Error logging engine
ini_set('error_log',              'MadelineProto.log'); // Logging file path

include 'vendor/autoload.php';

\error_log("Sanity Check");
//echo ("Sanity Check<br>" . PHP_EOL);

\set_error_handler(['\\danog\\MadelineProto\\Exception', 'exceptionErrorHandler']);
\set_exception_handler(['\\danog\\MadelineProto\\Exception', 'exceptionHandler']);

$session  = 'madeline.madeline';
$settings = [
    'logger' => [
        'logger'       => Logger::CALLABLE_LOGGER,
        'logger_param' => 'filter',
        'logger_level' => Logger::ULTRA_VERBOSE,
    ]
];
$mp = new API($session, $settings);
$mp->async(true);
$mp->loop(function () use ($mp, $eventHandler) {
    yield $mp->start();
    \error_clear_last();
    //$mp->setEventHandler($eventHandler);
    \error_clear_last();
});
$mp->loop();

error_log('Unknown!');
//Magic::shutdown(1);
exit();

function filter($entry, int $level): void
{
    $file = \basename(\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'], '.php');
    if (\is_string($entry)) {
        if (strpos($entry, 'req_pq_multi') !== false) {
            error_log("$file: $entry");
            if (strpos($entry, 'Could not resend req_pq_multi') !== false) {
                error_log('Session is bad!');
                $buffer = @\ob_get_clean() ?: '';
                $buffer .= '<html><body><h1>' . \htmlentities("Session is bad!") . '</h1></body></html>';
                Shutdown::removeCallback('duration');
                Shutdown::removeCallback('restarter');
                Shutdown::removeCallback(0);
                Shutdown::removeCallback(1);
                Shutdown::removeCallback(2);
                Shutdown::removeCallback(3);
                \ignore_user_abort(true);
                \header('Connection: close');
                \header('Content-Type: text/html');
                echo $buffer;
                \flush();
                exit(1);
            }
            //error_log('Session is terminated!');
            //exit('Account Unauthorized!');
            //throw new \ErrorException('Account Unauthorized!');
        }
    }
}

function exceptionErrorHandler($errno = 0, $errstr = null, $errfile = null, $errline = null)
{
    error_log("ExceptionError: $errstr");
    Magic::shutdown(1);

    exit();
    // If error is suppressed with @, don't throw an exception
    if (
        \error_reporting() === 0 ||
        \strpos($errstr, 'headers already sent') ||
        $errfile && (\strpos($errfile, 'vendor/amphp') !== false || \strpos($errfile, 'vendor/league') !== false)
    ) {
        return false;
    }
    echo ("errno: $errstr" . PHP_EOL);
    echo ("errstr: '$errstr??'''" . PHP_EOL);
    echo ("errfile: '$errfile??'''" . PHP_EOL);
    echo ("errline: '$errline??'''" . PHP_EOL);
    //throw new \danog\MadelineProto\Exception($errstr, $errno, null, $errfile, $errline);
}

function exceptionHandler($exception)
{
    error_log("Exception: $exception");
    Magic::shutdown(1);
    exit();
    //Logger::log($exception, Logger::FATAL_ERROR);
    //Magic::shutdown(1);
}
