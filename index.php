<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\Tools;
use danog\MadelineProto\Magic;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize, touch};

define("SCRIPT_START_TIME", \microtime(true));
define('SCRIPT_INFO',       'BASE_PLG V1.0.0'); // <== Do not change!
$clean = false;

require_once 'functions.php';
initPhp();
includeMadeline('composer');
require_once 'UserDate.php';
require_once 'FilteredLogger.php';
require_once 'Launch.php';
require_once 'BaseEventHandler.php';

$robotConfig = include('config.php');

define("MEMORY_LIMIT", \ini_get('memory_limit'));
define('REQUEST_URL',  \getRequestURL() ?? '');
define('USER_AGENT',   \getUserAgent()  ?? '');

$dataFiles = makeDataFiles(dirname(__FILE__) . '/data', ['startups', 'launches', 'creation', 'prevented']);
define("STARTUPS_FILE",  $dataFiles['startups']);
define("LAUNCHES_FILE",  $dataFiles['launches']);
define("CREATION_FILE",  $dataFiles['creation']);
define("PREVENTED_FILE", $dataFiles['prevented']);
unset($dataFiles);

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

Magic::classExists();
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
    echo ($text . PHP_EOL);
    exit(1);
}

$processId = \getmypid() === false ? 0 : \getmypid();
$sessionLock = null;
Logger::log("A new Process with pid $processId started at " . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
if (!acquireScriptLock(getSessionName($robotConfig), $sessionLock)) {
    closeConnection("Process already running!");
    savePreventedProcess(SCRIPT_START_TIME);
    removeShutdownHandlers();
    $strTime = $userDate->format(SCRIPT_START_TIME);
    Logger::log("Another instance of the script terminated at: " . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
    exit(1);
}

Logger::log('', Logger::ERROR);
Logger::log('=====================================================', Logger::ERROR);
Logger::log('Script started at: ' . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
Logger::log("Configurations: " . toJSON($robotConfig), Logger::ERROR);

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
            echo ('Oh, no!' . PHP_EOL);
            Logger::log('Oh, no!');
        },
        'duration'
    );
}

$session  = getSessionName($robotConfig);
$settings = $robotConfig['mp'][0]['settings'];
$mp = new API($session, $settings);
$newSession = newSession($mp);
Logger::log($newSession ? 'Creating a new session-file.' : 'Un-serializing an existing sesson-file.');
// To Be Fixed: n$ewSession is always true.
if ($newSession) {
    saveSessionCreation(CREATION_FILE, SCRIPT_START_TIME);
}
session_write_close();
\error_clear_last();

if ($signalHandler) {
    Shutdown::addCallback(
        function () use ($mp, &$signal, $userDate, &$clean) {
            $scriptEndTime = \microTime(true);
            //$e = new \Exception;
            //Logger::log($e->getTraceAsString(), Logger::ERROR);
            if ($clean) {
                // Clean Exit
            }
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
$authState = authorizationState($mp);
Logger::log("Authorization State: " . authorizationStateDesc($authState));
if ($authState === 4) {
    echo (PHP_EOL . "Invalid App, or the Session is corrupted!<br>" . PHP_EOL . PHP_EOL);
    Logger::log(PHP_EOL . "Invalid App, or the Session is corrupted!", Logger::ERROR);
}
Logger::log("Is Authorized: " . ($mp->hasAllAuth() ? 'true' : 'false'), Logger::ERROR);

safeStartAndLoop($mp, BaseEventHandler::class);
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

function closeConnection(string $message = 'OK!'): void
{
    if (PHP_SAPI === 'cli' || \headers_sent()) {
        return;
    }
    Logger::log($message, Logger::FATAL_ERROR);
    $buffer = @\ob_get_clean() ?: '';
    $buffer .= '<html><body><h1>' . \htmlentities($message) . '</h1></body></html>';
    \ignore_user_abort(true);
    \header('Connection: close');
    \header('Content-Type: text/html');
    echo $buffer;
    \flush();
    if (\function_exists('fastcgi_finish_request')) {
        \fastcgi_finish_request();
    }
}

function getSessionName(array $robotConfig): string
{
    return $robotConfig['mp'][0]['session'] ?? 'madeline.madeline';
}

function newSession(API $mp): bool
{
    return !Tools::getVar($mp, 'oldInstance');
}


function acquireScriptLock(string $sessionName, &$lock): bool
{
    $acquired = true;
    if (PHP_SAPI !== 'cli') {
        if (isset($_REQUEST['MadelineSelfRestart'])) {
            Logger::log("Self-restarted, restart token " . $_REQUEST['MadelineSelfRestart'], Logger::ERROR);
        }
        $lockfile = $sessionName . '.script.lock';
        if (!\file_exists($lockfile)) {
            \touch($lockfile);
            //Logger::log("Lock file '$lockfile' created!", Logger::ERROR);
        }
        $lock = \fopen($lockfile, 'r+');
        //Logger::log("Lock file '$lockfile' opened!", Logger::ERROR);
        $try = 1;
        $locked = false;
        while (!$locked) {
            $locked = \flock($lock, LOCK_EX | LOCK_NB);
            if (!$locked) {
                //Logger::log("Try $try of locking file '$lockfile' failed!", Logger::ERROR);
                if ($try++ >= 20) {
                    Logger::log("Locking file '$lockfile' failed!", Logger::ERROR); // Bot is running!
                    $acquired = false;
                    return $acquired;
                }
                \sleep(1);
            }
        }
        Logger::log("File '$lockfile' successfully locked!", Logger::ERROR); // Bot is not running!
        return $acquired;
    }
    return $acquired;
}

function saveSessionCreation(string $file, float $creationTime): void
{
    $strval = strval(intval(round($creationTime * 1000000)));
    file_put_contents($file, $strval);
}

function fetchSessionCreation(string $folder, string $file): float
{
    $strval = file_get_contents($folder . '/', $file);
    return round(intval($strval) / 1000000);
}

function get_absolute_path($path)
{
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
    $absolutes = array();
    foreach ($parts as $part) {
        if ('.' == $part) continue;
        if ('..' == $part) {
            array_pop($absolutes);
        } else {
            $absolutes[] = $part;
        }
    }
    return implode(DIRECTORY_SEPARATOR, $absolutes);
}

function removeShutdownHandlers(): void
{
    $class = new ReflectionClass('danog\MadelineProto\Shutdown');
    $callbacks = $class->getStaticPropertyValue('callbacks');

    Logger::log("Shutdown Callbacks Count: " . count($callbacks), Logger::ERROR);
    //Shutdown::removeCallback(null);

    register_shutdown_function(function () {
    });
}

function savePreventedProcess(float $startTime): void
{
    $data = strval(intval(round($startTime * 1000 * 1000)));
    file_put_contents(PREVENTED_FILE, $data, FILE_APPEND);
}

function absolutePath(string $path): string
{
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
    $absolutes = array();
    foreach ($parts as $part) {
        if ('.' == $part) continue;
        if ('..' == $part) {
            array_pop($absolutes);
        } else {
            $absolutes[] = $part;
        }
    }
    return implode(DIRECTORY_SEPARATOR, $absolutes);
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
