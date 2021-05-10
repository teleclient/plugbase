<?php

declare(strict_types=1);

use danog\madelineproto\API;
use danog\madelineproto\Logger;
use danog\madelineproto\MTProto;
use danog\madelineproto\Magic;
use danog\madelineproto\Shutdown;
use function Amp\File\{get, put, exists, getSize};

require_once 'Handler.php';
require_once 'AbstractHandler.php';
require_once 'Loop.php';
require_once 'AbstractLoop.php';

class BaseEventHandler extends \danog\MadelineProto\EventHandler
{
    private API   $mp;

    private array $handlers;
    private array $loops;

    private float $handlerConstructed;
    private float $handlerUnserialized;

    private int      $robotId;
    private string   $robotName;
    private bool     $canExecute;
    private bool     $authorizationRevoked;

    function __construct(\danog\MadelineProto\APIWrapper $apiWrapper)
    {
        parent::__construct($apiWrapper);

        $now = microtime(true);
        $this->handlerConstructed  = $now;
        $this->handlerUnserialized = $now;

        Logger::Log("EventHandler constructed at " . $this->formatTime($now), Logger::ERROR);

        $this->initBaseEventHandler($now);
    }

    public function __wakeup()
    {
        $now = microtime(true);
        $this->handlerUnserialized = $now;

        Logger::log('EventHandler unserialized at ' . $this->formatTime($now), Logger::ERROR);

        $this->initBaseEventHandler($now);
    }

    private function initBaseEventHandler(float $now)
    {
        Logger::log('EventHandler initialized at ' . $this->formatTime($now), Logger::ERROR);
        $this->authorizationRevoked = false;

        $handlerNames   = $this->getHandlerNames();
        $this->handlers = [];
        foreach ($handlerNames as $handlerName) {
            $className = $handlerName . 'Handler';
            $newClass = new $className($this);
            $created = get_class($newClass);
            if ($created === false) {
                removeShutdownHandlers();
                throw new ErrorException("Invalid Handler Plugin name: '$className'");
            }
            Logger::log("Handler Plugin '$created' created!", Logger::ERROR);
            $this->handlers[$handlerName] = $newClass;
        }
    }

    public function __destruct()
    {
        $eh = $this;
        $reason  = $eh->getStopReason();
        $session = $eh->getSessionName();
        if ($reason === 'logout') {
            if (file_exists($session)) {
                unlink($session);
                Logger::log("Session file $session is deleted!", Logger::ERROR);
            }
            if (file_exists($session . '.lock')) {
                unlink($session . '.lock');
                Logger::log("Session lock file $session.lock is deleted!", Logger::ERROR);
            }
            if (file_exists($session . '.script.lock')) {
                unlink($session . '.script.lock');
                Logger::log("Session lock file $session.script.lock is deleted!", Logger::ERROR);
            }
        }
        Logger::log("Destructing the 'BaseEventHandler'! Reason:'$reason'  Session:'$session'", Logger::ERROR);
    }

    public function onStart(): \Generator
    {
        $this->canExecute = false;
        $this->stopReason = "UNKNOWN";

        $record = \Launch::updateLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME);
        $record = \Launch::floatToDate($record, $this->getUserDate());
        Logger::log("EventHandler Update Run Record: " . toJSON($record, false), Logger::ERROR);

        $start = $this->formatTime(microtime(true));
        yield $this->logger("EventHandler::onStart executed at $start", Logger::ERROR);

        $self = yield $this->getSelf();
        yield $this->setSelf($self);

        foreach ($this->handlers as $handlerName => $handler) {
            $className = get_class($handler);
            if (method_exists($handler, 'onStart')) {
                Logger::log("Handler plugin '$className' onStart method invoked!", Logger::ERROR);
                yield $handler->onStart($this);
            } else {
                Logger::log("Handler plugin '$className'  has no onStart method!", Logger::ERROR);
            }
        }
    }

    public function finalizeStart(API $mp): \Generator
    {
        //Logger::log("Entering the method BaseEventHandler::finalizeStart!", Logger::ERROR);
        $this->mp = $mp;
        $mpVersion = MTProto::RELEASE . ' (' . MTProto::V . ', ' . Magic::$revision . ')';
        Logger::log("MadelineProto version: '$mpVersion'", Logger::ERROR);

        $loopNames = $this->getLoopNames(); // $this->robotConfig['mp'][0]['loops'];
        $this->loops = [];
        foreach ($loopNames as $loopName) {
            $lowerName = strtolower($loopName);
            $className = $loopName . 'Loop';
            if (!class_exists($className)) {
                removeShutdownHandlers();
                throw new ErrorException("Loop plugin class $className doesn't exist!", Logger::ERROR);
            }
            $newClass  = new $className($mp, $this);
            $created   = get_class($newClass);
            if ($created === false) {
                removeShutdownHandlers();
                throw new ErrorException("Invalid Loop Plugin name: '$className'");
            }
            Logger::log("Loop Plugin '$created' created!", Logger::ERROR);

            $loopState = $this->__get("loopstate_$lowerName");
            if ($loopState !== 'on' && $loopState !== 'off') {
                if (method_exists($newClass, 'initialState')) {
                    $loopState = $newClass->initialState() === 'on' ? 'on' : 'off';
                } else {
                    $loopState = 'on';
                }
                $this->__set("loopstate_$lowerName", $loopState);
            }

            if (method_exists($newClass, 'onStart')) {
                Logger::log("Loop plugin '$className' onStart method invoked!", Logger::ERROR);
                yield $newClass->onStart();
            } else {
                Logger::log("Lopp plugin '$className'  has no onStart method!", Logger::ERROR);
            }
            $newClass->start();
            $this->loops[strtolower($loopName)] = $newClass;

            Logger::log("Loop: lower:'$lowerName', shortname:'$loopName', classname:'$className'", Logger::ERROR);
        }
        //Logger::log("Exiting the method BaseEventHandler::finalizeStart!", Logger::ERROR);
    }

    public function onAny(array $update): \Generator
    {
        $verb = $this->possiblyVerb($update, $this->getPrefixes(), 30);
        //$msgFront = substr($update['message']['message'] ?? '', 0, 30);
        //$this->logger("PossibleVerb: '$verb', recentMessage: " . ($this->recentMessage($update) ? 'yes' : 'no') . "  msgType: {$update['_']}  msg: '$msgFront'", Logger::ERROR);
        //$isNew = floatval($update['message']['date'] ?? 0) >= $this->getScriptStarted();
        if (!$this->canExecute && $verb !== '' && $this->recentMessage($update)) {
            $this->recentMessage($update);
            $this->canExecute = true;
            $this->logger('Command-Processing engine started at ' . $this->formatTime(), Logger::ERROR);
        }
        //$vars = ['verb' => $verb];
        if ($verb !== '') {
            $msgDate   = $this->formatTime(floatval($update['message']['date']));
            $nowDate   = $this->formatTime(\microtime(true));
            $startDate = $this->formatTime($this->getScriptStarted());
            $age = $this->canExecute() ? 'new' : 'old';
            $this->logger("$age verb:'$verb', msg:$msgDate, start:$startDate, now:$nowDate", Logger::ERROR);
            if ($age === 'new') {
                //$vars = computeVars($update, $this);
            }
        }
        $vars = computeVars($update, $this);

        foreach ($this->handlers as $handlerName => $handler) {
            $processed = yield ($handler($update, $vars, $this));
        }
    }

    public function authorizationRevoked(): Generator
    {
        $this->authorizationRevoked = true;
        $text = "FATAL: Authorization revoked at " . $this->formatTime() . '!';
        $this->logger($text, Logger::FATAL_ERROR);
        if (Shutdown::removeCallback('restarter')) {
            $this->logger('Self-Restarter disabled.', Logger::ERROR);
        }
        yield $this->stop();
        $this->setStopReason('sessiondelete');
        $this->destroyLoops();
        trigger_error($text, E_USER_ERROR);
        exit(1);
    }
    public function isAuthorizationRevoked(): bool
    {
        return $this->authorizationRevoked;
    }

    public function getRobotConfig(): array
    {
        return Magic::$storage['robot_config'];
    }

    public function setSelf(array $self): void
    {
        $this->robotId = $self['id'];
        $name = strval($self['id']);
        if (isset($self['username'])) {
            $name = $self['username'];
        } elseif (isset($self['first_name'])) {
            $name = $self['first_name'];
        } elseif (isset($self['last_name'])) {
            $name = $self['last_name'];
        }
        $this->robotName = $name;
    }

    public function getRobotId(): int
    {
        return $this->robotId;
    }

    public function getRobotName(): string
    {
        return $this->robotName;
    }

    public function getAdminIds(): array
    {
        return $this->getRobotConfig()['adminIds'] ?? [];
    }
    public function getOfficeId(): ?int
    {
        return $this->officeConfig['officeid'] ?? null;
    }

    public function getUserDate(): \UserDate
    {
        return Magic::$storage['user_date'];
    }

    public function getZone(): string
    {
        return $this->getUserDate()->getZone();
    }

    function formatTime(float $microtime = null, string $format = 'H:i:s.v'): string
    {
        $microtime = $microtime ?? \microtime(true);
        return $microtime < 100 ? 'UNAVAILABLE' : ($this->getUserDate()->format($microtime, $format));
    }

    public function canExecute(): bool
    {
        return $this->canExecute ?? false;
    }

    public function getStopReason(): string
    {
        return Magic::$storage['stop_reason'];
    }
    public function setStopReason(string $stopReason): void
    {
        Magic::$storage['stop_reason'] = $stopReason;
    }

    function getEditMessage(): bool
    {
        return $this->getRobotConfig()['edit'] ?? true;
    }

    function getSessionName(): string
    {
        return Magic::$storage['session_name'];
    }

    function getLoopNames(): array
    {
        return $this->getRobotConfig()['mp'][0]['loops'] ?? [];
    }

    function getHandlerNames(): array
    {
        return $this->getRobotConfig()['mp'][0]['handlers'] ?? [];
    }

    function getPrefixes(): string
    {
        return $this->getRobotConfig()['prefixes'] ?? '/!';
    }

    function getScriptStarted(): float
    {
        return Magic::$storage['script_start'];
    }
    function getHandlerConstructed(): float
    {
        return $this->handlerConstructed;
    }

    function getHandlerUnserialized(): float
    {
        return $this->handlerUnserialized;
    }

    function recentUpdate(array $update): bool
    {
        return floatval($update['message']['date'] ?? 0) >= $this->getScriptStarted();
    }

    function getHandlers(): array
    {
        return $this->handlers;
    }
    function getLoops(): array
    {
        return $this->loops;
    }

    function getHandler(string $name): object
    {
        return $this->handlers[$name];
    }
    function getLoop(string $name): object
    {
        return $this->loops[$name];
    }

    function getScriptInfo(): string
    {
        return Magic::$storage['script_info'];
    }

    public function getLoopState(string $loopName): string
    {
        $loopState = $this->__get("loopstate_$loopName");
        if ($loopState !== 'on' && $loopState !== 'off') {
            throw new ErrorException("Unknown state '$loopState' for $loopName loop plugin!");
        }
        return $loopState;
    }
    public function setLoopState(string $loopName, string $loopState): void
    {
        $this->__set("loopstate_$loopName", $loopState);
    }

    public function destroyLoops()
    {
        foreach ($this->loops as $name => $loop) {
            $this->logger("The $name loop plugin destroyed!", Logger::ERROR);
            unset($this->loops[$name]);
        }
        gc_collect_cycles();
    }

    public function destroyHandlers()
    {
        foreach ($this->handlers as $name => $handler) {
            $this->logger("The $name handler plugin destroyed!", Logger::ERROR);
            unset($this->handlers[$name]);
        }
        gc_collect_cycles();
    }

    public function getSessionCreation(string $directory, string $file): \Generator // float
    {
        $fullpath = $directory . $file;
        $strTime  = yield \Amp\File\get($fullpath);
        if ($strTime === null || $strTime === '') {
            $microTime = $this->getScriptStarted();
            $strTime   = strval(intval(round($microTime * 1000000)));
            yield \Amp\File\put($fullpath, $strTime);
        } else {
            $microTime = round(intval($strTime) / 1000000);
        }
        return $microTime;
    }

    public static function possiblyVerb(array $update, string $prefixes, int $maxlen = 30): string
    {
        if ($update['_'] === 'updateNewMessage' && isset($update['message']['message'])) {
            $msg = $update['message']['message'];
            if (strlen($msg) >= 2 && strpos($prefixes, $msg[0]) !== false) {
                $spaceloc = strpos($msg, ' ');
                $verb = $spaceloc === false ? substr($msg, 1) : substr($msg, 1, $spaceloc - 1);
                if (strlen($verb) < $maxlen && ctype_alnum(str_replace('_', '', $verb))) {
                    return strtolower($verb);
                }
            }
        }
        return '';
    }

    public function getHelpText(string $prefixes = '!/'): string
    {
        if (file_exists('data/help.txt')) {
            $text = \file_get_contents('data/help.txt');
        } else {
            $text = '' .
                '<b>Robot Instructions:</b><br>' .
                '<br>' .
                '>> <b>/help</b><br>' .
                '   To print the robot commands<br>' .
                '>> <b>/status</b><br>' .
                '   To query the status of the robot.<br>' .
                '>> <b>/stats</b><br>' .
                '   To query the statistics of the robot.<br>' .
                '>> <b>/notif OFF / ON 20</b><br>' .
                '   No event notification or notify every 20 secs.<br>' .
                '>> <b>/crash</b><br>' .
                '   To generate an exception for testing.<br>' .
                '>> <b>/restart</b><br>' .
                '   To restart the robot.<br>' .
                '>> <b>/stop</b><br>' .
                '   To stop the script.<br>' .
                '>> <b>/logout</b><br>' .
                '   To terminate the robot\'s session.<br>' .
                '<br>' .
                '<b>**Valid prefixes are / and !</b><br>';
        }
        return $text;
    }
}
