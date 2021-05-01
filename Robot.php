<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;
use danog\MadelineProto\API;
use danog\MadelineProto\Magic;
use danog\MadelineProto\MTProto;
use Amp\Loop;
use function Amp\File\{get, put, exists, getSize, touch};

require 'utils/functions.php';
require 'utils/UserDate.php';
require 'utils/FilteredLogger.php';
require 'utils/Launch.php';
require 'BaseEventHandler.php';


class Robot
{
    const SCRIPT_INFO   = 'BASE_PLG V2.0.0';
    const EVENT_HANDLER = BaseEventHandler::class;

    public  float    $robotStartTime;
    private string   $signal;
    private string   $stopReason;
    private API      $mp;
    private array    $robotConfig;
    private UserDate $userDate;

    public function __construct()
    {
        $this->robotStartTime = \microtime(true);
        $robotStartTime = $this->robotStartTime;

        $this->initPhp();
        includeMadeline('composer');

        Magic::classExists();

        $this->signal     = '';
        $this->stopReason = '';

        define("MEMORY_LIMIT", \ini_get('memory_limit'));
        define('REQUEST_URL',  \getRequestURL() ?? '');
        define('USER_AGENT',   \getUserAgent()  ?? '');

        $dataFiles = $this->makeDataFiles(dirname(__FILE__) . '/data', ['startups', 'launches', 'creation']);
        define("STARTUPS_FILE",  $dataFiles['startups']);
        define("LAUNCHES_FILE",  $dataFiles['launches']);
        define("CREATION_FILE",  $dataFiles['creation']);
        unset($dataFiles);

        if (!file_exists('config.php')) {
            echo ("Fatal Error: The file config.php is missing!" . PHP_EOL);
            echo ("Rename the config.sample.php file to config.php and modify it based on your needs!" . PHP_EOL);
            die();
        }
        $this->robotConfig = include('config.php');
        $this->userDate    = new \UserDate($this->robotConfig['zone'] ?? 'America/Los_Angeles');

        $this->includeHandlers($this->robotConfig);
        $this->includeLoops($this->robotConfig);

        $this->setSignalHandler();

        $restartsCount = checkTooManyRestarts(STARTUPS_FILE);
        $maxrestarts   = $this->robotConfig['maxrestarts'] ?? 10;
        if ($restartsCount > $maxrestarts) {
            $text = 'More than $maxrestarts times restarted within a minute. Permanently shutting down ....';
            Logger::log($text, Logger::ERROR);
            Logger::log(self::SCRIPT_INFO . ' on ' . hostname() . ' is stopping at ' . $this->userDate->format(SCRIPT_START_TIME), Logger::ERROR);
            echo ($text . PHP_EOL);
            exit(1);
        }

        if (isset($_REQUEST['MadelineSelfRestart'])) {
            Logger::log("Self-restarted, restart token " . $_REQUEST['MadelineSelfRestart'], Logger::ERROR);
        }

        $processId = \getmypid() === false ? 0 : \getmypid();
        $sessionLock = null;
        Logger::log("A new Process with pid $processId started at " . $this->userDate->format(SCRIPT_START_TIME), Logger::ERROR);
        if (!acquireScriptLock($this->getSessionName($this->robotConfig), $sessionLock)) {
            closeConnection("Bot is already running!");
            $this->removeShutdownHandlers();
            $launch = \Launch::appendBlockedRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'blocked');
            Logger::log("Another instance of the script terminated at: " . $this->userDate->format(SCRIPT_START_TIME), Logger::ERROR);
            exit(1);
        }

        $safeConfig = $this->safeConfig($this->robotConfig);
        Logger::log('', Logger::ERROR);
        Logger::log('=====================================================', Logger::ERROR);
        Logger::log('Script started at: ' . $this->userDate->format(SCRIPT_START_TIME), Logger::ERROR);
        Logger::log("Configurations: " . toJSON($safeConfig), Logger::ERROR);
        unset($safeConfig);

        $launch = \Launch::appendLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, 'kill');
        $launch = \Launch::floatToDate($launch, $this->userDate);
        Logger::log("Appended Run Record: " . toJSON($launch, false), Logger::ERROR);
        unset($launch);

        $this->checkWebServerName();

        $this->reserveShutdownHandler();
        $session    = $this->getSessionName($this->robotConfig);
        $settings   = $this->robotConfig['mp'][0]['settings'] ?? [];
        $newSession = file_exists($session) ? false : true;
        $this->mp = new API($session, $settings);
        Logger::log($newSession ? 'Creating a new session-file.' : 'Un-serializing an existing sesson-file.');
        if ($newSession) {
            $this->saveSessionCreation(CREATION_FILE, SCRIPT_START_TIME);
        }
        \error_clear_last();
        $this->setShutdownHandler();

        $authState = authorizationState($this->mp);
        Logger::log("Authorization State: " . authorizationStateDesc($authState));
        if ($authState === 4) {
            echo (PHP_EOL . "Invalid App, or the Session is corrupted!<br>" . PHP_EOL . PHP_EOL);
            Logger::log("Invalid App, or the Session is corrupted!", Logger::ERROR);
        }
        $hasAllAuth = $authState === -2 ? false : $this->mp->hasAllAuth();
        Logger::log("Is Authorized: " . ($hasAllAuth ? 'true' : 'false'), Logger::ERROR);
        if ($authState === MTProto::LOGGED_IN && !$hasAllAuth) {
            Logger::log("The Session is terminated or corrupted!", Logger::ERROR);
        }
    }

    public function start()
    {
        $this->mp->async(true);
        //$mp->__set('config', $robotConfig);

        $robot = $this;
        $this->mp->loop(function () use ($robot) {
            $mp = $robot->getMadelineProto();
            $eventHandlerClass = $robot->getEventHandlerClass();
            $errors = [];
            while (true) {
                try {
                    $started = false;
                    $stateBefore = authorizationState($mp);
                    if (!$mp->hasAllAuth() || authorizationState($mp) !== 3) {
                        yield $mp->logger("Not Logged-in!", Logger::ERROR);
                    }

                    $me = yield $mp->start();

                    $stateAfter = authorizationState($mp);
                    yield $mp->logger("Authorization State: {Before_Start: '$stateBefore', After_Start: '$stateAfter'}", Logger::ERROR);
                    if (!$mp->hasAllAuth() || authorizationState($mp) !== 3) {
                        yield $mp->logger("Unsuccessful Login!", Logger::ERROR);
                        throw new ErrorException('Unsuccessful Login!');
                    } else {
                        yield $mp->logger("Robot is currently logged-in!", Logger::ERROR);
                    }
                    if (!$me || !is_array($me)) {
                        throw new ErrorException('Invalid Self object');
                    }
                    \closeConnection('Bot was started!');

                    if (!$mp->hasEventHandler()) {
                        yield $mp->setEventHandler($eventHandlerClass);
                        yield $mp->logger("EventHandler is set!", Logger::ERROR);
                    } else {
                        yield $mp->setEventHandler($eventHandlerClass); // For now.  To be investigated
                        yield $mp->logger("EventHandler was already set!", Logger::ERROR);
                    }

                    if (\method_exists($eventHandlerClass, 'finalizeStart')) {
                        $eh = $mp->getEventHandler($eventHandlerClass);
                        yield $eh->finalizeStart($mp);
                    }

                    $started = true;
                    \danog\madelineproto\Tools::wait(yield from $mp->API->loop());
                    break;
                } catch (\Throwable $e) {
                    $errors = [\time() => $errors[\time()] ?? 0];
                    $errors[\time()]++;
                    $fatal = \danog\madelineproto\Logger::FATAL_ERROR;
                    if ($errors[\time()] > 10 && (!$mp->inited() || !$started)) {
                        yield $mp->logger->logger("More than 10 errors in a second and not inited, exiting!", $fatal);
                        break;
                    }
                    yield $mp->logger->logger((string) $e, $fatal);
                    yield $mp->report("Surfaced: $e");
                }
            }
        });
    }

    private function includeHandlers(array $robotConfig): void
    {
        $handlerNames = $robotConfig['mp'][0]['handlers'] ?? [];
        foreach ($handlerNames as $handlerName) {
            $handlerFileName = 'handlers/' . $handlerName . 'Handler.php';
            require $handlerFileName;
        }
    }

    private function includeLoops(array $robotConfig): void
    {
        $loopNames = $robotConfig['mp'][0]['loops'] ?? [];
        foreach ($loopNames as $loopName) {
            $loopFileName = 'loops/' . $loopName . 'Loop.php';
            require $loopFileName;
        }
    }

    private function makeDataFiles(string $dirPath, array $baseNames): array
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
    private function makeDataFilesAsync(string $dirPath, array $baseNames): \Generator
    {
        // To Be Implemented as Async using Amp Iterator.
        if (!yield exists($dirPath)) {
            yield mkDir($dirPath);
        }
        $absPaths['directory'] = $dirPath;
        foreach ($baseNames as $baseName) {
            $absPath = $dirPath . '/' . $baseName . '.txt';
            if (!yield exists($absPath)) {
                yield touch($absPath);
            }
            $absPaths[$baseName] = $absPath;
        }
        return $absPaths;
    }

    public function setSignal(string $signal): void
    {
        $this->$signal = $signal;
    }
    public function getSignal(): string
    {
        return $this->signal;
    }

    private function setSignalHandler()
    {
        if (\defined('SIGINT')) {
            try {
                $robot = $this;
                Loop::run(function () use ($robot) {
                    $siginit = Loop::onSignal(SIGINT, static function () use ($robot) {
                        $robot->setSignal('sigint');
                        Logger::log('Robot received SIGINT signal', Logger::FATAL_ERROR);
                        Magic::shutdown(1);
                    });
                    Loop::unreference($siginit);

                    $sigterm = Loop::onSignal(SIGTERM, static function () use ($robot) {
                        $robot->setSignal('sigint');
                        Logger::log('Robot received SIGTERM signal', Logger::FATAL_ERROR);
                        Magic::shutdown(1);
                    });
                    Loop::unreference($sigterm);
                });
            } catch (\Throwable $e) {
            }
        }
    }

    private function reserveShutdownHandler(): void
    {
        Shutdown::addCallback(
            static function (): void {
                Logger::log('Duration dummy placeholder shutdown routine executed!', Logger::ERROR);
            },
            'duration'
        );
    }

    private function setShutdownHandler(): void
    {
        $robot = $this;
        $mp    = $this->mp;
        $userDate = $this->userDate;
        Shutdown::addCallback(
            function () use ($robot, $mp, $userDate) {
                $scriptEndTime = \microTime(true);
                $stopReason = 'nullapi';
                $signal = $robot->getSignal();
                if ($signal !== '') {
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
                $msg = self::SCRIPT_INFO . " stopped due to $stopReason!  Execution duration: " . $duration . "!";
                Logger::log($msg, Logger::ERROR);
            },
            'duration'
        );
    }

    private function checkWebServerName(): void
    {
        if (PHP_SAPI !== 'cli') {
            if (!\getWebServerName()) {
                $hostname = $this->robotConfig['host'] ?? null;
                if ($hostname) {
                    \setWebServerName($hostname);
                } else {
                    $text = "To enable the restart, the config->host must be defined!";
                    echo ($text . PHP_EOL);
                    Logger::log($text, Logger::ERROR);
                }
            }
        }
    }

    public function initPhp(): void
    {
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
    }

    private function removeShutdownHandlers(): void
    {
        $class = new ReflectionClass('danog\MadelineProto\Shutdown');
        $callbacks = $class->getStaticPropertyValue('callbacks');
        Logger::log("Shutdown Callbacks Count: " . count($callbacks), Logger::ERROR);

        register_shutdown_function(function () {
        });
    }

    private function saveSessionCreation(string $file, float $creationTime): void
    {
        $strval = strval(intval(round($creationTime * 1000000)));
        file_put_contents($file, $strval);
    }

    private function getSessionName(array $robotConfig): string
    {
        return $robotConfig['mp'][0]['session'] ?? 'madeline.madeline';
    }

    public function safeConfig(array $robotConfig): array
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

    public function getMadelineProto(): API
    {
        return $this->mp;
    }

    public function getEventHandlerClass(): string
    {
        return self::EVENT_HANDLER;
    }
}
