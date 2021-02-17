<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\Magic;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize};

define("SCRIPT_START_TIME", \microtime(true));
define('SCRIPT_INFO',       'BASE_P V0.2.0'); // <== Do not change!

require_once 'functions.php';
initPhp();
includeMadeline('phar');
require_once  'UserDate.php';
require_once    'Launch.php';
require_once 'BaseEventHandler.php';

define('ROBOT_CONFIG',   include('config.php'));
define("MEMORY_LIMIT",   \ini_get('memory_limit'));
define('REQUEST_URL',    \getRequestURL() ?? '');
define('USER_AGENT',     \getUserAgent() ?? '');
define("DATA_DIRECTORY", \makeDataDirectory('data'));
define("STARTUPS_FILE",  \makeDataFile(DATA_DIRECTORY, 'startups.txt'));
define("LAUNCHES_FILE",  \makeDataFile(DATA_DIRECTORY, 'launches.txt'));

$userDate = new \UserDate(ROBOT_CONFIG['zone']);
error_log('Script started at: ' . $userDate->format(SCRIPT_START_TIME));

$restartsCount = checkTooManyRestarts(STARTUPS_FILE);
if ($restartsCount > ROBOT_CONFIG['maxrestarts']) {
    $text = 'More than ' . ROBOT_CONFIG['maxrestarts'] . ' times restarted within a minute. Permanently shutting down ....';
    Logger::log($text, Logger::ERROR);
    Logger::log(SCRIPT_INFO . ' on ' . hostname() . ' is stopping at ' . $userDate->format(SCRIPT_START_TIME), Logger::ERROR);
    exit($text . PHP_EOL);
}

$launch = \Launch::appendLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'kill');
error_log("Appended Run Record: " . toJSON($launch));
unset($launch);

if (PHP_SAPI !== 'cli') {
    if (!\getWebServerName()) {
        \setWebServerName(ROBOT_CONFIG['config->host']);
        if (!\getWebServerName()) {
            $text = "To enable the restart, the config->host must be defined!";
            echo ($text . PHP_EOL);
            error_log($text);
        }
    }
}

$signal  = null;
Loop::run(function () use (&$signal) {
    if (\defined('SIGINT')) {
        $siginit = Loop::onSignal(SIGINT, static function () use (&$signal) {
            $signal = 'sigint';
            Logger::log('Got sigint', Logger::FATAL_ERROR);
            Magic::shutdown(1);
        });
        Loop::unreference($siginit);

        $sigterm = Loop::onSignal(SIGTERM, static function () use (&$signal) {
            $signal = 'sigterm';
            Logger::log('Got sigterm', Logger::FATAL_ERROR);
            Magic::shutdown(1);
        });
        Loop::unreference($sigterm);
    }
});

Shutdown::addCallback(
    static function (): void {
    },
    'duration'
);

$session  = ROBOT_CONFIG['mp'][0]['session'];
$settings = ROBOT_CONFIG['mp'][0]['settings'];
$mp = new API($session, $settings);
$mp->updateSettings(['logger_level' => Logger::NOTICE]);

$signal = null;
Shutdown::addCallback(
    function () use ($mp, $signal) {
        echo (PHP_EOL . 'Shutting down ....<br>' . PHP_EOL);
        $scriptEndTime = \microTime(true);
        $stopReason = 'nullapi';
        if ($signal !== null) {
            $stopReason = $signal;
        } elseif ($mp) {
            try {
                $stopReason = $mp->getEventHandler()->getStopReason();
                if (false && $stopReason === 'UNKNOWN') {
                    $error = \error_get_last();
                    $stopReason = isset($error) ? 'error' : $stopReason;
                }
            } catch (\TypeError $e) {
                $stopReason = 'sigterm';
            }
        }
        $duration   = \timeDiffFormatted($scriptEndTime, SCRIPT_START_TIME);
        $peakMemory = \getPeakMemory();
        $record     = \Launch::updateLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, $scriptEndTime, $stopReason, $peakMemory);
        Logger::log(toJSON($record), Logger::ERROR);
        $msg = SCRIPT_INFO . " stopped due to $stopReason!  Execution duration: " . $duration;
        Logger::log($msg, Logger::ERROR);
        error_log($msg);
    },
    'duration'
);

$authState = authorizationState($mp);
error_log("Authorization State: " . authorizationStateDesc($authState));
if ($authState === 4) {
    echo (PHP_EOL . "Invalid App, or the Session is corrupted!<br>" . PHP_EOL . PHP_EOL);
    Logger::log(PHP_EOL . "Invalid App, or the Session is corrupted!", Logger::ERROR);
}
error_log("Is Authorized: " . ($mp->hasAllAuth() ? 'true' : 'false'));

safeStartAndLoop($mp, BaseEventHandler::class, ROBOT_CONFIG);

echo ('Bye, bye!<br>' . PHP_EOL);
error_log('Bye, bye!');
