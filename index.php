<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Magic;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize, touch};

define('SCRIPT_INFO',       'BASE_PLG V1.1.2'); // <== Do not change!
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
$clean      = false;
$signal     = null; // DON NOT DELETE
$stopReason = null;
//$newSession = saveNewSessionCreation(string $folder, string $file, float $creationTime);

define("MEMORY_LIMIT", \ini_get('memory_limit'));
define('REQUEST_URL',  \getRequestURL() ?? '');
define('USER_AGENT',   \getUserAgent()  ?? '');

$dataFiles = makeDataFiles(dirname(__FILE__) . '/data', ['startups', 'launches']);
define("DATA_DIRECTORY", $dataFiles['directory']);
define("STARTUPS_FILE",  $dataFiles['startups']);
define("LAUNCHES_FILE",  $dataFiles['launches']);
//define("CREATION_FILE",  $dataFiles['creation']);
unset($dataFiles);

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
if (PHP_SAPI !== 'cli') {
    $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
} else {
    $link = "Terminal on $hostname";
}

$restartsCount = checkTooManyRestarts(STARTUPS_FILE);
$maxrestarts   = $robotConfig['maxrestarts'] ?? 10;
if ($restartsCount > $maxrestarts) {
    $text = "The script '$scriptStartStr' restarted more than $maxrestarts times within a minute. Permanently shutting down ....";
    Logger::log($text, Logger::ERROR);
    echo ($text . PHP_EOL);
    Logger::log("$scriptInfo on '$hostname' is stopping at $scriptStartStr!", Logger::ERROR);
    exit(1);
}

$processId   = \getmypid() === false ? 0 : \getmypid();
$sessionLock = null;
$maxAquireRety = 30;
if (!acquireScriptLock(getSessionName($robotConfig), $sessionLock, $maxAquireRety)) {
    Logger::log("An instance of the script '" . SCRIPT_INFO . "' with pid $processId started at $scriptStartStr via $link!", Logger::ERROR);
    closeConnection("An instance of the Bot with script $scriptInfo is already running!");
    removeShutdownHandlers();
    $quitTime = $scriptStartStr = $userDate->format(microtime(true));
    $launch = \Launch::appendBlockedRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'blocked');
    Logger::log("The instance of the script with pid $processId couldn't acquire the lock after $maxAquireRety seconds, therfore terminated at $quitTime!", Logger::ERROR);
    exit(1);
}

Logger::log('', Logger::ERROR);
Logger::log('=====================================================', Logger::ERROR);
Logger::log("The script $scriptInfo started at: $scriptStartStr with pid $processId via $link!", Logger::ERROR);
Logger::log("Configurations: " . toJSON(safeConfig($robotConfig)), Logger::ERROR);
if (isset($_REQUEST['MadelineSelfRestart'])) {
    Logger::log("Self-restarted, restart token " . $_REQUEST['MadelineSelfRestart'], Logger::ERROR);
}

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

$session    = getSessionName($robotConfig);
$settings   = $robotConfig['mp'][0]['settings'] ?? [];
$newSession = !file_exists($session);
$mp = new API($session, $settings);
Logger::log($newSession ? 'Creating a new session-file.' : 'Unserializing an existing sesson-file.');
if ($newSession) {
    saveNewSessionCreation(DATA_DIRECTORY, 'creation.txt', SCRIPT_START_TIME);
}
\error_clear_last();
if ($filteredLogger) {
    $filteredLogger->setAPI($mp);
}
if (!$newSession && $mp->hasAllAuth() || authorizationState($mp) === MTProto::NOT_LOGGED_IN) {
    Logger::log('The session is logged out of, externally terminated, or its account is deleted!');
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

function getSessionName(array $robotConfig): string
{
    return $robotConfig['mp'][0]['session'] ?? 'madeline.madeline';
}

function acquireScriptLock(string $sessionName, &$lock, $retryCount = 10): bool
{
    $acquired = true;
    if (PHP_SAPI !== 'cli') {
        $lockfile = $sessionName . '.script.lock';
        if (!\file_exists($lockfile)) {
            \touch($lockfile);
        }
        $lock = \fopen($lockfile, 'r+');
        $try = 1;
        $locked = false;
        while (!$locked) {
            $locked = \flock($lock, LOCK_EX | LOCK_NB);
            if (!$locked) {
                if ($try++ >= $retryCount) {
                    $acquired = false;
                    return $acquired;
                }
                \sleep(1);
            }
        }
        return $acquired;
    }
    return $acquired;
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

/*
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
*/

function removeShutdownHandlers(): void
{
    $class = new ReflectionClass('danog\MadelineProto\Shutdown');
    $callbacks = $class->getStaticPropertyValue('callbacks');
    register_shutdown_function(function () {
    });
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

/**
 * Close the connection to the browser but continue processing the operation
 * @param $body
 */
function closeConnection(string $message = 'OK', int $responseCode = 200): void
{
    if (PHP_SAPI === 'cli' || \headers_sent()) {
        return;
    }
    Logger::log($message, Logger::FATAL_ERROR);

    $buffer  = @\ob_get_clean() ?: '';
    $buffer .= '<html><body><h1>' . \htmlentities($message) . '</h1></body></html>';

    // Cause we are clever and don't want the rest of the script to be bound by a timeout.
    // Set to zero so no time limit is imposed from here on out.
    set_time_limit(0);

    // if using (u)sleep in an XHR the next requests are still hanging until sleep finishes
    session_write_close();

    // Client disconnect should NOT abort our script execution
    ignore_user_abort(true);

    // Clean (erase) the output buffer and turn off output buffering
    // in case there was anything up in there to begin with.
    if (ob_get_length() > 0) {
        ob_end_clean();
    }

    // Turn on output buffering, because ... we just turned it off ...
    // if it was on.
    ob_start();

    echo $buffer;

    // Return the length of the output buffer
    $size = ob_get_length();

    // send headers to tell the browser to close the connection
    // remember, the headers must be called prior to any actual
    // input being sent via our flush(es) below.
    header("Connection: close\r\n");
    header("Content-Encoding: none\r\n");
    header("Content-Length: $size");

    // Set the HTTP response code
    // this is only available in PHP 5.4.0 or greater
    http_response_code($responseCode);

    // Flush (send) the output buffer and turn off output buffering
    ob_end_flush();

    // Flush (send) the output buffer
    // This looks like overkill, but trust me. I know, you really don't need this
    // unless you do need it, in which case, you will be glad you had it!
    @ob_flush();

    // Flush system output buffer
    // I know, more over kill looking stuff, but this
    // Flushes the system write buffers of PHP and whatever backend PHP is using
    // (CGI, a web server, etc). This attempts to push current output all the way
    // to the browser with a few caveats.
    flush();
}

function adjustSettings(array $robotConfig, string $scriptInfo): array
{
    $replacement = ['mp' => [0 => ['settings' => ['app_info' => ['app_version' => $scriptInfo]]]]];
    return array_replace_recursive($robotConfig, $replacement);
}
