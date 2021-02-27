<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\Magic;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize};

define("SCRIPT_START_TIME", \microtime(true));
define('SCRIPT_INFO',       'BASE_P V1.0.0'); // <== Do not change!

require_once 'functions.php';
initPhp();
includeMadeline('composer');
require_once 'UserDate.php';
require_once 'FilteredLogger.php';
require_once 'Launch.php';
require_once 'BaseEventHandler.php';

$robotConfig = include('config.php');

define("MEMORY_LIMIT",   \ini_get('memory_limit'));
define('REQUEST_URL',    \getRequestURL() ?? '');
define('USER_AGENT',     \getUserAgent() ?? '');
define("DATA_DIRECTORY", \makeDataDirectory('data'));
define("STARTUPS_FILE",  \makeDataFile(DATA_DIRECTORY, 'startups.txt'));
define("LAUNCHES_FILE",  \makeDataFile(DATA_DIRECTORY, 'launches.txt'));

$signalHandler = true;
$signal        = null;

if ($signalHandler && \defined('SIGINT')) {
    try {
        Loop::run(function () use (&$signal) {
            $siginit = Loop::onSignal(SIGINT, static function () use (&$signal) {
                $signal = 'sigint';
                Logger::log('Robot received SIGINT signal', Logger::FATAL_ERROR);
                Magic::shutdown(1);
            });
            Loop::unreference($siginit);

            $sigterm = Loop::onSignal(SIGTERM, static function () use (&$signal) {
                $signal = 'sigterm';
                Logger::log('Robot received SIGTERM signal', Logger::FATAL_ERROR);
                Magic::shutdown(1);
            });
            Loop::unreference($sigterm);
        });
    } catch (\Throwable $e) {
    }
}

if ($robotConfig['mp'][0]['filterlog'] ?? false) {
    $filteredLogger = new FilteredLogger($robotConfig, 0);
} else {
    $logger = Logger::getLoggerFromSettings($robotConfig['mp'][0]['settings']);
    \error_clear_last();
}

$userDate = new \UserDate($robotConfig['zone']);

$restartsCount = checkTooManyRestarts(STARTUPS_FILE);
if ($restartsCount > ($robotConfig['maxrestarts'] ?? 10)) {
    $text = 'More than ' . $robotConfig['maxrestarts'] . ' times restarted within a minute. Permanently shutting down ....';
    Logger::log($text, Logger::ERROR);
    Logger::log(SCRIPT_INFO . ' on ' . hostname() . ' is stopping at ' . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
    exit($text . PHP_EOL);
}

Logger::log('', Logger::ERROR);
Logger::log('=====================================================', Logger::ERROR);
Logger::log('Script started at: ' . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
$launch = \Launch::appendLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'kill');
$launch = \Launch::floatToDate($launch, $userDate);
Logger::log("Appended Run Record: " . toJSON($launch, false), Logger::ERROR);
unset($launch);

if (PHP_SAPI !== 'cli') {
    if (!\getWebServerName()) {
        \setWebServerName($robotConfig['config->host']);
        if (!\getWebServerName()) {
            $text = "To enable the restart, the config->host must be defined!";
            echo ($text . PHP_EOL);
            Logger::log($text, Logger::ERROR);
        }
    }
}

if (false && \defined('SIGINT')) {
    try {
        Loop::unreference(Loop::onSignal(SIGINT, static function () use (&$signal) {
            $signal = 'sigint';
            Logger::log('Got sigint', Logger::FATAL_ERROR);
            Magic::shutdown(1);
        }));
        Loop::unreference(Loop::onSignal(SIGTERM, static function () use (&$signal) {
            $signal = 'sigterm';
            Logger::log('Got sigterm', Logger::FATAL_ERROR);
            Magic::shutdown(1);
        }));
    } catch (\Throwable $e) {
    }
}

if ($signalHandler) {
    Shutdown::addCallback(
        static function (): void {
            echo ('Oh no!' . PHP_EOL);
        },
        'duration'
    );
}

$session  = $robotConfig['mp'][0]['session'];
$settings = $robotConfig['mp'][0]['settings'];
\set_error_handler(['\\danog\\MadelineProto\\Exception', 'exceptionErrorHandler']);
\set_exception_handler(['\\danog\\MadelineProto\\Exception', 'exceptionHandler']);
$mp = new API($session, $settings);
\error_clear_last();

if ($signalHandler) {
    Shutdown::addCallback(
        function () use ($mp, &$signal, $userDate) {
            $scriptEndTime = \microTime(true);
            //$e = new \Exception;
            //Logger::log($e->getTraceAsString(), Logger::ERROR);
            $stopReason = 'nullapi';
            //var_dump(Tools::getVar($mp->API, 'destructing').PHP_EOL); => bool(false)
            if ($signal !== null) {
                $stopReason = $signal;
            } elseif (!$mp) {
                // The external API class is not instansiated
                $stopReason = 'nullapi';
            } elseif (!isset($mp->API)) {
                $stopReason = 'destruct';
            } elseif (!$mp->API->event_handler) {
                // EventHandler is not set
                // Is shutdown during the start() function execution
                $error = \error_get_last();
                if (isset($error)) {
                    Logger::log("LAST PHP ERROR: " . toJSON($error), Logger::ERROR);
                }
                $stopReason = isset($error) ? 'error' : 'destruct';
            } else {
                // EventHandler is set and instantiated
                $eh = $mp->getEventHandler();
                $stopReason = $eh->getStopReason();
                if ($stopReason === 'UNKNOWN') {
                    $error = \error_get_last();
                    if (isset($error)) {
                        Logger::log("LAST PHP ERROR: " . toJSON($error), Logger::ERROR);
                        $stopReason = isset($error) ? 'error' : $stopReason;
                    } else {
                        $stopReason = 'exit';
                    }
                }
            }

            echo (PHP_EOL . "Shutting down due to '$stopReason' ....<br>" . PHP_EOL);
            Logger::log("Shutting down due to '$stopReason' ....", Logger::ERROR);
            $record = \Launch::finalizeLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, $scriptEndTime, $stopReason);
            $record = \Launch::floatToDate($record, $userDate);
            Logger::log("Final Update Run Record: " . toJSON($record, false), Logger::ERROR);
            $duration = \UserDate::duration(SCRIPT_START_TIME, $scriptEndTime);
            $msg = SCRIPT_INFO . " stopped due to $stopReason!  Execution duration: " . $duration . "!";
            Logger::log($msg, Logger::ERROR);
        },
        'duration'
    );
}
/*
$authState = authorizationState($mp);
Logger::log("Authorization State: " . authorizationStateDesc($authState));
if ($authState === 4) {
    echo (PHP_EOL . "Invalid App, or the Session is corrupted!<br>" . PHP_EOL . PHP_EOL);
    Logger::log(PHP_EOL . "Invalid App, or the Session is corrupted!", Logger::ERROR);
}
Logger::log("Is Authorized: " . ($mp->hasAllAuth() ? 'true' : 'false'), Logger::ERROR);
*/
//safeStartAndLoop($mp, BaseEventHandler::class);


simpleStartAndLoop($mp, BaseEventHandler::class);
//$mp->startAndLoop(BaseEventHandler::class);
\error_clear_last();
echo ('Bye, bye!<br>' . PHP_EOL);
Logger::log('Bye, bye!', Logger::ERROR);

//\set_error_handler(['\\danog\\MadelineProto\\Exception', 'exceptionErrorHandler']);
//\set_exception_handler(['\\danog\\MadelineProto\\Exception', 'exceptionHandler']);

function exceptionErrorHandler($errno = 0, $errstr = null, $errfile = null, $errline = null)
{
    Logger::log($errstr, Logger::FATAL_ERROR);
    Magic::shutdown(1);
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
    throw new \danog\MadelineProto\Exception($errstr, $errno, null, $errfile, $errline);
}

function exceptionHandler($exception)
{
    Logger::log($exception, Logger::FATAL_ERROR);
    Magic::shutdown(1);
}
