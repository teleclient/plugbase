<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\Tools;
use danog\MadelineProto\Magic;
use danog\MadelineProto\MTProto;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize, touch};

define("SCRIPT_START_TIME", \microtime(true));
define('SCRIPT_INFO',       'BASE_PLG V1.1.0'); // <== Do not change!
$clean = false;

require_once 'functions.php';
initPhp();
includeMadeline('composer');

require_once 'UserDate.php';
require_once 'FilteredLogger.php';
require_once 'Launch.php';
require_once 'BaseEventHandler.php';

$robotConfig = include('config.php');
includeHandlers($robotConfig['mp'][0]['handlers']);
includeLoops($robotConfig['mp'][0]['loops']);

$userDate = new \UserDate($robotConfig['zone'] ?? 'America/Los_Angeles');

define("MEMORY_LIMIT", \ini_get('memory_limit'));
define('REQUEST_URL',  \getRequestURL() ?? '');
define('USER_AGENT',   \getUserAgent()  ?? '');

$dataFiles = makeDataFiles(dirname(__FILE__) . '/data', ['startups', 'launches', 'creation']);
define("STARTUPS_FILE",  $dataFiles['startups']);
define("LAUNCHES_FILE",  $dataFiles['launches']);
define("CREATION_FILE",  $dataFiles['creation']);
unset($dataFiles);

$signal = null;

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

Magic::classExists();
if ($robotConfig['mp'][0]['filterlog'] ?? false) {
    $filteredLogger = new FilteredLogger($robotConfig, 0);
} else {
    $logger = Logger::getLoggerFromSettings($robotConfig['mp'][0]['settings']);
    \error_clear_last();
}

$restartsCount = checkTooManyRestarts(STARTUPS_FILE);
$maxrestarts   = $robotConfig['maxrestarts'] ?? 10;
if ($restartsCount > $maxrestarts) {
    $text = 'More than $maxrestarts times restarted within a minute. Permanently shutting down ....';
    Logger::log($text, Logger::ERROR);
    Logger::log(SCRIPT_INFO . ' on ' . hostname() . ' is stopping at ' . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
    echo ($text . PHP_EOL);
    exit(1);
}

if (isset($_REQUEST['MadelineSelfRestart'])) {
    Logger::log("Self-restarted, restart token " . $_REQUEST['MadelineSelfRestart'], Logger::ERROR);
}

$processId = \getmypid() === false ? 0 : \getmypid();
$sessionLock = null;
Logger::log("A new Process with pid $processId started at " . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
if (!acquireScriptLock(getSessionName($robotConfig), $sessionLock)) {
    closeConnection("Bot is already running!");
    removeShutdownHandlers();
    $launch = \Launch::appendBlockedRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'blocked');
    Logger::log("Another instance of the script terminated at: " . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
    exit(1);
}

$safeConfig = safeConfig($robotConfig);
Logger::log('', Logger::ERROR);
Logger::log('=====================================================', Logger::ERROR);
Logger::log('Script started at: ' . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
Logger::log("Configurations: " . toJSON($safeConfig), Logger::ERROR);
unset($safeConfig);

$launch = \Launch::appendLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'kill');
$launch = \Launch::floatToDate($launch, $userDate);
Logger::log("Appended Run Record: " . toJSON($launch, false), Logger::ERROR);
unset($launch);

if (PHP_SAPI !== 'cli') {
    if (!\getWebServerName()) {
        $hostname = $robotConfig['host'] ?? null;
        if ($hostname) {
            \setWebServerName($hostname);
        } else {
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

Shutdown::addCallback(
    static function (): void {
        Logger::log('Duration dummy placeholder shutdown routine executed!', Logger::ERROR);
    },
    'duration'
);

$session    = getSessionName($robotConfig);
$settings   = $robotConfig['mp'][0]['settings'] ?? [];
$newSession = file_exists($session) ? false : true;
$mp = new API($session, $settings);
Logger::log($newSession ? 'Creating a new session-file.' : 'Un-serializing an existing sesson-file.');
if ($newSession) {
    saveSessionCreation(CREATION_FILE, SCRIPT_START_TIME);
}
\error_clear_last();

Shutdown::addCallback(
    function () use ($mp, &$signal, $userDate, &$clean) {
        $scriptEndTime = \microTime(true);
        //$e = new \Exception;
        //Logger::log($e->getTraceAsString(), Logger::ERROR);
        if ($clean) {
            // Clean Exit
        }
        $stopReason = 'nullapi';
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

$authState = authorizationState($mp);
Logger::log("Authorization State: " . authorizationStateDesc($authState));
if ($authState === 4) {
    echo (PHP_EOL . "Invalid App, or the Session is corrupted!<br>" . PHP_EOL . PHP_EOL);
    Logger::log("Invalid App, or the Session is corrupted!", Logger::ERROR);
}
$hasAllAuth = $authState === -2 ? false : $mp->hasAllAuth();
Logger::log("Is Authorized: " . ($hasAllAuth ? 'true' : 'false'), Logger::ERROR);
if ($authState === MTProto::LOGGED_IN && !$hasAllAuth) {
    echo (PHP_EOL . "The Session is terminated or corrupted!<br>" . PHP_EOL . PHP_EOL);
    Logger::log("The Session is terminated or corrupted!", Logger::ERROR);
}

//$mp->async(true);
//$mp->loop(function () use ($mp) {
//$serialized = file_get_contents('authser.authkey');
//$exportedAuth = unserialize($serialized);
//yield $mp->start();
//$exportedAuth = yield $mp->exportAuthorization();
//$serialized   = serialize($exportedAuth);
//file_put_contents('authser2.authkey', $serialized);
//$authorization = yield $mp->importAuthorization($exportedAuth);
//Logger::log("Authorization: " . toJSON($authorization), Logger::ERROR);
//});

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
                    //Logger::log("Locking file '$lockfile' failed!", Logger::ERROR); // Bot is running!
                    $acquired = false;
                    return $acquired;
                }
                \sleep(1);
            }
        }
        //Logger::log("File '$lockfile' successfully locked!", Logger::ERROR); // Bot is not running!
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

function includeHandlers(array $handlers): void
{
    foreach ($handlers as $handler) {
        include "handlers/$handler.php";
    }
}

function includeLoops(array $loops): void
{
    foreach ($loops as $loop) {
        include "loops/$loop.php";
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
