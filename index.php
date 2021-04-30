<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Magic;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize, touch};

define('SCRIPT_INFO',       'BASE_PLG V1.2.0'); // <== Do not change!
define("SCRIPT_START_TIME", \microtime(true));

$robotConfig = require('config.php');
$robotConfig = adjustSettings($robotConfig, SCRIPT_INFO);
require_once 'utils/functions.php';
initPhp();
includeMadeline($robotConfig['source'] ?? 'phar 5.1.34');
require_once 'utils/UserDate.php';
require_once 'utils/FilteredLogger.php';
require_once 'utils/Launch.php';
require_once 'BaseEventHandler.php';
includeHandlers($robotConfig);
includeLoops($robotConfig);

$userDate = new \UserDate($robotConfig['zone'] ?? 'America/Los_Angeles');
$clean       = false;
$signal      = null; // DON NOT DELETE
$stopReason  = null;
$sessionLock = null; // DO NOT DELETE
$sessionName = __DIR__ . '/' . $robotConfig['mp'][0]['session'];

define("MEMORY_LIMIT", \ini_get('memory_limit'));
define('REQUEST_URL',  \getRequestURL() ?? '');
define('USER_AGENT',   \getUserAgent()  ?? '');

$dataFiles = makeDataFiles(dirname(__FILE__) . '/data', ['startups', 'launches']);
define("DATA_DIRECTORY", $dataFiles['directory']);
define("STARTUPS_FILE",  $dataFiles['startups']);
define("LAUNCHES_FILE",  $dataFiles['launches']);
unset($dataFiles);
define("CREATION_FILE_NAME", 'creation.txt');

$filteredLogger = null;
Magic::classExists();
if ($robotConfig['mp'][0]['filterlog'] ?? false) {
    $filteredLogger = new FilteredLogger($robotConfig, 0);
} else {
    $logger = Logger::getLoggerFromSettings($robotConfig['mp'][0]['settings']);
    \error_clear_last();
}

$scriptStartStr = $userDate->format(SCRIPT_START_TIME);
$scriptInfo = SCRIPT_INFO;
$hostname   = hostname() ?? 'UNDEFINED';

$restartsCount = checkTooManyRestarts(STARTUPS_FILE);
$maxrestarts   = $robotConfig['maxrestarts'] ?? 10;
if ($restartsCount > $maxrestarts) {
    $text = "The script '$scriptStartStr' restarted more than $maxrestarts times within a minute. Permanently shutting down ....";
    Logger::log($text, Logger::ERROR);
    echo ($text . PHP_EOL);
    Logger::log("$scriptInfo on '$hostname' is stopping at $scriptStartStr!", Logger::ERROR);
    exit(1);
}

$fullLink = "Terminal on $hostname";
$post     = "";
if (PHP_SAPI !== 'cli') {
    $fullLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
}

$processId     = \getmypid() === false ? 0 : \getmypid();
$maxAquireRety = 15;
if (!acquireScriptLock($sessionName, /*By Reference*/ $sessionLock, $maxAquireRety)) {
    Logger::log('', Logger::ERROR);
    Logger::log("An instance of the script '" . SCRIPT_INFO . "' with pid $processId started at $scriptStartStr via $fullLink and post data " . toJSON($_POST, false), Logger::ERROR);
    closeConnection("An instance of the Bot with script $scriptInfo is already running!");
    removeShutdownHandlers();
    $quitTime = $scriptStartStr = $userDate->format(microtime(true));
    $launch = \Launch::appendBlockedRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'blocked');
    Logger::log("The instance of the script with pid $processId couldn't acquire the lock after $maxAquireRety seconds, therfore terminated at $quitTime!", Logger::ERROR);
    Logger::log('', Logger::ERROR);
    exit(1);
}

Logger::log('', Logger::ERROR);
Logger::log('=====================================================', Logger::ERROR);
Logger::log("The script $scriptInfo started at: $scriptStartStr with pid $processId", Logger::ERROR);
Logger::log("started via $fullLink carrying post data: " . toJSON($_POST, false), Logger::ERROR);
Logger::log("Configurations: " . toJSON(safeConfig($robotConfig)), Logger::ERROR);
Logger::log('', Logger::ERROR);

if (PHP_SAPI !== 'cli') {
    if (!\getWebServerName()) {
        $configuredHostname = $robotConfig['host'] ?? null;
        if ($configuredHostname) {
            \setWebServerName($configuredHostname);
        } else {
            $text = "To enable the robot's restart, the config->host must be defined!";
            echo ($text . PHP_EOL);
            Logger::log($text, Logger::ERROR);
        }
    }
}

unset($restartCount);
unset($maxrestarts);
unset($configuredHostname);
unset($scriptStartStr);

$launch = \Launch::appendLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'kill');
$launch = \Launch::floatToDate($launch, $userDate);
Logger::log("Appended Run Record: " . toJSON($launch, false), Logger::ERROR);
unset($launch);

if (\defined('SIGINT')) {
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

Shutdown::addCallback(
    static function (): void {
        Logger::log('Duration dummy placeholder shutdown routine executed!', Logger::ERROR);
    },
    'duration'
);

$newSession = !file_exists(DATA_DIRECTORY . '/' . CREATION_FILE_NAME); // is created immediately after the very first successful login.

Logger::log(file_exists($sessionName) ? 'Creating a new session-file.' : 'Unserializing an existing sesson-file.');
$settings = $robotConfig['mp'][0]['settings'] ?? [];
Logger::log('Configured API Session: ' . $sessionName, Logger::ERROR);
$mp = new API($sessionName, $settings);
$mp->logger('API Session: ' . $mp->session, Logger::ERROR);
\error_clear_last();
if ($filteredLogger) {
    $filteredLogger->setAPI($mp);
}
if (!$newSession && (!$mp->hasAllAuth() || authorizationState($mp) !== MTProto::LOGGED_IN)) {
    Logger::log("newSession:" . ($newSession ? "'true'" : "'false'"), Logger::ERROR);
    Logger::log("All  Auth: " . ($mp->hasAllAuth() ? "'true'" : "'false'"), Logger::ERROR);
    Logger::log("Auth State: " . authorizationStateDesc(authorizationState($mp)), Logger::ERROR);
    Logger::log('The session is logged out of, externally terminated, or its account is deleted!', Logger::ERROR);
    \closeConnection("The robot's session is logged out of, externally terminated, or its account is deleted!");
    removeShutdownHandlers();
    exit(0);
}
Shutdown::addCallback(
    function () use ($mp, &$signal, $userDate, &$clean, &$stopReason) {
        $scriptEndTime = \microTime(true);

        if ($clean) {
            // Clean Exit
        }

        if ($signal !== null) {
            $stopReason = $signal;
        } elseif (!$mp) {
            // The external API class is not instansiated
            $stopReason = 'nullapi';
        } elseif ($stopReason !== null) {
            //
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
            foreach ($eh->getLoops() as $loopName => $loopObj) {
                unset($loopObj);
            }
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
        Logger::log("Shutting down due to '$stopReason' ....", Logger::ERROR);
        $record = \Launch::finalizeLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, $scriptEndTime, $stopReason);
        $record = \Launch::floatToDate($record, $userDate);
        Logger::log("Final Update Run Record: " . toJSON($record, true), Logger::ERROR);
        $duration = \UserDate::duration(SCRIPT_START_TIME, $scriptEndTime);
        $msg = SCRIPT_INFO . " stopped due to '$stopReason'!  Execution duration: " . $duration . "!";
        Logger::log($msg, Logger::ERROR);
    },
    'duration'
);

Logger::log('Before start and loop!');
safeStartAndLoop($mp, BaseEventHandler::class, $stopReason, $newSession);

\error_clear_last();
echo ('Bye, bye!<br>' . PHP_EOL);
Logger::log('Bye, bye!', Logger::ERROR);
$clean = true;
exit(0);


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
    echo ("errno: $errstr"          . PHP_EOL);
    echo ("errstr: '$errstr??'''"   . PHP_EOL);
    echo ("errfile: '$errfile??'''" . PHP_EOL);
    echo ("errline: '$errline??'''" . PHP_EOL);
    throw new \danog\MadelineProto\Exception($errstr, $errno, null, $errfile, $errline);
}

function exceptionHandler($exception)
{
    Logger::log($exception, Logger::FATAL_ERROR);
    Magic::shutdown(1);
}

function safeConfig(array $robotConfig): array
{
    $safeConfig = $robotConfig;
    array_walk_recursive($safeConfig, function (&$value, $key) {
        if ($key     === 'phone')    $value = '';
        elseif ($key === 'password') $value = '';
        elseif ($key === 'api_id')   $value = 0;
        elseif ($key === 'api_hash') $value = '';
    });
    return $safeConfig;
}

function saveNewSessionCreation(string $folder, string $file, float $creationTime): bool
{
    $fullName = $folder . '/' . $file;
    if (file_exists($fullName)) {
        return false;
    } else {
        $strval = strval(intval(round($creationTime * 1000000)));
        file_put_contents($fullName, $strval);
        return true;
    }
}

function fetchSessionCreation(string $folder, string $file): float
{
    $strval = file_get_contents($folder . '/' . $file);
    return round(intval($strval) / 1000000);
}

function makeDataFiles(string $dirPath, array $baseNames): array
{
    // To Be Implemented as Async using Amp Iterator.
    if (!file_exists($dirPath)) {
        \mkdir($dirPath);
    }
    $absPaths['directory'] = $dirPath;
    foreach ($baseNames as $baseName) {
        $absPath = $dirPath . '/' . $baseName . '.txt';
        if (!file_exists($absPath)) {
            \touch($absPath);
        }
        $absPaths[$baseName] = $absPath;
    }
    return $absPaths;
}

function includeHandlers(array $robotConfig): void
{
    $handlerNames = $robotConfig['mp'][0]['handlers'] ?? [];
    foreach ($handlerNames as $handlerName) {
        $handlerFileName = 'handlers/' . $handlerName . 'Handler.php';
        require $handlerFileName;
    }
}

function includeLoops(array $robotConfig): void
{
    $loopNames = $robotConfig['mp'][0]['loops'] ?? [];
    foreach ($loopNames as $loopName) {
        $loopFileName = 'loops/' . $loopName . 'Loop.php';
        require $loopFileName;
    }
}

function adjustSettings(array $robotConfig, string $scriptInfo): array
{
    $replacement = ['mp' => [0 => ['settings' => ['app_info' => ['app_version' => $scriptInfo]]]]];
    return array_replace_recursive($robotConfig, $replacement);
}
