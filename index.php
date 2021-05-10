<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Magic;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize, touch};

$scriptStart = \microtime(true);

if (!file_exists('config.php')) {
    echo ("Fatal Error: The file config.php is missing!" . PHP_EOL);
    echo ("Rename the config.sample.php file to config.php and modify it based on your needs!" . PHP_EOL);
    die();
}

require 'utils/functions.php';
require 'utils/UserDate.php';
initPhp();
includeMadeline('composer');

Magic::$storage['script_info']   = 'BASE_PLG V1.2.0'; // <== Do not change!
Magic::$storage['script_start']  = $scriptStart;
Magic::$storage['robot_config']  = adjustSettings(require('config.php'));
Magic::$storage['process_id']    = \getmypid() === false ? 0 : \getmypid();
Magic::$storage['madeline_ver']  = MTProto::V > 137 ? 6 : (MTProto::V > 105 ? 5 : 4);
Magic::$storage['php_interface'] = PHP_SAPI === 'cli' ? 'cli' : 'web';
Magic::$storage['launch_method'] = \getLaunchMethod();
Magic::$storage['user_date']     =  new \UserDate(Magic::$storage['robot_config']['zone'] ?? 'America/Los_Angeles');
Magic::$storage['stop_reason']   = 'UNKNOWN';
Magic::$storage['signal']        = null;
Magic::$storage['clean']         = false;
Magic::$storage['session_name']  = __DIR__ . '/' . Magic::$storage['robot_config']['mp'][0]['session'];
Magic::$storage['phone']         = Magic::$storage['robot_config']['mp'][0]['phone']    ?? null;
Magic::$storage['password']      = Magic::$storage['robot_config']['mp'][0]['password'] ?? null;
Magic::$storage['settings']      = Magic::$storage['robot_config']['mp'][0]['settings'] ?? [];
Magic::$storage['data_folder']   = 'data';
Magic::$storage['startups_file'] = Magic::$storage['data_folder'] . '/' . 'startups.txt';
Magic::$storage['launches_file'] = Magic::$storage['data_folder'] . '/' . 'launches.txt';
Magic::$storage['creation_file'] = Magic::$storage['data_folder'] . '/' . 'creation.txt';

if (!file_exists(Magic::$storage['data_folder'])) {
    \mkdir(Magic::$storage['data_folder']);
    echo (Magic::$storage['data_folder'] . " directory created!\n");
}

require 'utils/FilteredLogger.php';
require 'utils/Launch.php';
require 'BaseEventHandler.php';
require 'Start.php';
includePlugins(Magic::$storage['robot_config']);

$sessionLock = null;  // DO NOT DELETE

define("MEMORY_LIMIT", \ini_get('memory_limit'));
define('REQUEST_URL',  \getRequestURL() ?? '');
define('USER_AGENT',   \getUserAgent()  ?? '');

$filteredLogger = null;
Magic::classExists();
if (Magic::$storage['robot_config']['mp'][0]['filterlog'] ?? false) {
    $robotConfig = Magic::$storage['robot_config'];
    $filteredLogger = new FilteredLogger($robotConfig, 0);
    Magic::$storage['robot_config'] = $robotConfig;
    unset($robotConfig);
} else {
    $logger = Logger::getLoggerFromSettings(Magic::$storage['settings']);
    \error_clear_last();
}

$userDate       = Magic::$storage['user_date'];
$scriptStartStr = $userDate->format(Magic::$storage['script_start']);
$scriptInfo     = Magic::$storage['script_info'];
$hostname       = hostname() ?? 'UNDEFINED';

$restartsCount = checkTooManyRestarts(Magic::$storage['startups_file']);
$maxrestarts   = Magic::$storage['settings']['maxrestarts'] ?? 5;
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

$processId     = Magic::$storage['process_id'];
$maxAquireRety = 15;
if (!acquireScriptLock(Magic::$storage['session_name'], /*By Reference*/ $sessionLock, $maxAquireRety)) {
    Logger::log('', Logger::ERROR);
    Logger::log("An instance of the script '" . Magic::$storage['script_info'] . "' with pid $processId started at $scriptStartStr via $fullLink and post data " . toJSON($_POST, false), Logger::ERROR);
    closeConnection("An instance of the Bot with script $scriptInfo is already running!");
    removeShutdownHandlers();
    $quitTime = $scriptStartStr = $userDate->format(microtime(true));
    $launch = \Launch::appendBlockedRecord(Magic::$storage['script_start'], 'blocked');
    Logger::log("The instance of the script with pid $processId couldn't acquire the lock after $maxAquireRety seconds, therfore terminated at $quitTime!", Logger::ERROR);
    Logger::log('', Logger::ERROR);
    exit(1);
}

Logger::log('', Logger::ERROR);
Logger::log('=====================================================', Logger::ERROR);
Logger::log("The script $scriptInfo started at: $scriptStartStr with pid $processId", Logger::ERROR);
Logger::log("started via $fullLink carrying post data: " . toJSON($_POST, false), Logger::ERROR);
Logger::log("Configurations: " . toJSON(safeConfig(Magic::$storage['robot_config'])), Logger::ERROR);
Logger::log('', Logger::ERROR);

if (PHP_SAPI !== 'cli') {
    if (!\getWebServerName()) {
        $configuredHostname = Magic::$storage['robot_config']['host'] ?? null;
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

$launch = \Launch::appendLaunchRecord(Magic::$storage['script_start'], 'kill');
$launch = \Launch::floatToDate($launch, $userDate);
Logger::log("Appended Run Record: " . toJSON($launch, false), Logger::ERROR);
unset($launch);

if (\defined('SIGINT')) {
    try {
        Loop::run(function () {
            $siginit = Loop::onSignal(SIGINT, static function () {
                Magic::$storage['signal'] = 'sigint';
                Logger::log('Robot received SIGINT signal', Logger::FATAL_ERROR);
                Magic::shutdown(1);
            });
            Loop::unreference($siginit);

            $sigterm = Loop::onSignal(SIGTERM, static function () {
                Magic::$storage['signal'] = 'sigterm';
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

Logger::log(file_exists(Magic::$storage['session_name']) ? 'Unserializing an existing sesson-file.' : 'Creating a new session-file.');
Logger::log('Configured API Session: ' . Magic::$storage['session_name'], Logger::ERROR);
//--------------------------------------------------------------------------
$mp = new API(Magic::$storage['session_name'], Magic::$storage['settings']);
$mp->async(true);
//--------------------------------------------------------------------------
$mp->logger('API Session: ' . $mp->session, Logger::ERROR);
//\error_clear_last();

if ($filteredLogger) {
    $filteredLogger->setAPI($mp);
}

Shutdown::addCallback(
    function () use ($mp, $userDate) {
        $scriptEndTime = \microTime(true);

        if (Magic::$storage['clean']) {
            // Clean Exit
        }

        $stopReason = Magic::$storage['stop_reason'];
        if (Magic::$storage['signal'] !== null) {
            $stopReason = Magic::$storage['signal'];
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
        Magic::$storage['stop_reason'] = $stopReason;
        Logger::log("Shutting down due to '$stopReason' ....", Logger::ERROR);
        $record = \Launch::finalizeLaunchRecord(Magic::$storage['script_start'], $scriptEndTime, $stopReason);
        $record = \Launch::floatToDate($record, $userDate);
        Logger::log("Final Update Run Record: " . toJSON($record, true), Logger::ERROR);
        $duration = \UserDate::duration(Magic::$storage['script_start'], $scriptEndTime);
        $msg = Magic::$storage['script_info']  . " stopped due to '$stopReason'!  Execution duration: " . $duration . "!";
        Logger::log($msg, Logger::ERROR);
    },
    'duration'
);

if (authorizationState($mp) === MTProto::LOGGED_IN && !$mp->hasAllAuth()) {
    Logger::log("Been Authorized: 'true'", Logger::ERROR);
    Logger::log("Currently Authorized: " . ($mp->hasAllAuth() ? "'true'" : "'false'"), Logger::ERROR);
    Logger::log("Logging State: " . authorizationStateDesc(authorizationState($mp)), Logger::ERROR);
    $text = "The robot's session is logged out of, externally terminated, or its logged-in account is deleted!";
    Logger::log($text, Logger::ERROR);
    \closeConnection($text);
    Magic::$storage['stop_reason'] = 'badsession';
    if (PHP_SAPI === 'cli') {
        echo ($text . PHP_EOL);
    }
    gc_collect_cycles();
    set_error_handler(function () {
        $text = "Again! The robot's session is logged out of, externally terminated, or its account is deleted!";
        Logger::log($text, Logger::ERROR);
        register_shutdown_function(function () {
        });
        exit(0);
    });
    $var = 5 / 0;
    trigger_error($text, E_USER_ERROR);

    //posix_kill(Magic::$storage['process_id'], SIGINT);
    $eh = $mp->getEventHandler();
    if ($eh) {
        $eh->destroyLoops();
        $eh->destroyHandlers();
        unset($eh);
        gc_collect_cycles();
    }
    $mp->API->unreference();
    removeShutdownHandlers();
    //Shutdown::shutdown();
    //pcntl_signal_dispatch();
    Loop::stop();
    //Magic::shutdown(0);
    unset($mp);
    gc_collect_cycles();
    $var = 5 / 0;
    trigger_error($text, E_USER_ERROR);
    exit(0);
}
$mp->logger('We are here now: ' . $mp->session, Logger::ERROR);
$mp->loop(static function () use ($mp) {
    $mp->logger('We are here now 2: ' . $mp->session, Logger::ERROR);
    $authStateBefore = authorizationState($mp);
    $mp->logger('We are here now 3: ' . $mp->session, Logger::ERROR);
    $stateBeforeStr  = authorizationStateDesc($authStateBefore);
    $stopReasonSaved = Magic::$storage['stop_reason'];
    Magic::$storage['stop_reason'] = $stateBeforeStr;
    $mp->logger("About to call Start::startuser with authorization state '$stateBeforeStr'", Logger::ERROR);
    $start = new Start($mp);
    $me = yield $start->startUser(Magic::$storage['phone'], Magic::$storage['password']);
    Magic::$storage['stop_reason'] = $stopReasonSaved;
});

\error_clear_last();
echo ('Bye, bye!<br>' . PHP_EOL);
Logger::log('Bye, bye!', Logger::ERROR);
Magic::$storage['clean'] = true;
exit(0);

Logger::log('Before start and loop!');
safeStartAndLoop($mp, BaseEventHandler::class);


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

function saveNewSessionCreation(float $creationTime): bool
{
    $fullName = Magic::$storage['creation_file'];
    if (file_exists($fullName)) {
        return false;
    } else {
        $strval = strval(intval(round($creationTime * 1000000)));
        file_put_contents($fullName, $strval);
        return true;
    }
}
function fetchSessionCreation(): float
{
    $strval = file_get_contents(Magic::$storage['creation_file']);
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

function includePlugins(): void
{
    $robotConfig = Magic::$storage['robot_config'];

    $handlerNames = $robotConfig['mp'][0]['handlers'] ?? [];
    foreach ($handlerNames as $handlerName) {
        $handlerFileName = 'handlers/' . $handlerName . 'Handler.php';
        require $handlerFileName;
    }

    $loopNames = $robotConfig['mp'][0]['loops'] ?? [];
    foreach ($loopNames as $loopName) {
        $loopFileName = 'loops/' . $loopName . 'Loop.php';
        require $loopFileName;
    }
}

function adjustSettings(array $robotConfig): array
{
    $replacement = ['mp' => [0 => ['settings' => ['app_info' => ['app_version' => Magic::$storage['script_info']]]]]];
    return array_replace_recursive($robotConfig, $replacement);
}

function beenAuthorized(): bool
{
    return file_exists(Magic::$storage['creation_file']);
}
