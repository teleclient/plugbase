<?php

declare(strict_types=1);

use danog\madelineproto\API;
use danog\madelineproto\Logger;
use danog\madelineproto\MTProto;
use danog\madelineproto\Magic;
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

    private array    $robotConfig;
    private int      $robotId;
    private string   $robotName;
    private UserDate $userDate;
    private bool     $canExecute;
    private string   $stopReason;
    private string   $prefixes;

    function __construct(\danog\MadelineProto\APIWrapper $apiWrapper)
    {
        parent::__construct($apiWrapper);

        $now = microtime(true);
        $this->handlerConstructed  = $now;
        $this->handlerUnserialized = $now;

        $this->robotConfig = $GLOBALS['robotConfig'];
        $this->userDate    = new \UserDate($this->robotConfig['zone']);

        Logger::Log("EventHandler constructed at " . $this->userDate->format($now), Logger::ERROR);

        $this->initBaseEventHandler($now);
    }

    public function __wakeup()
    {
        $now = microtime(true);
        $this->handlerUnserialized = $now;

        $this->robotConfig = $GLOBALS['robotConfig'];
        $this->userDate    = new \UserDate($this->robotConfig['zone']);

        Logger::log('EventHandler unserialized at ' . $this->userDate->format($now), Logger::ERROR);

        $this->initBaseEventHandler($now);
    }

    private function initBaseEventHandler(float $now)
    {
        Logger::log('EventHandler initialized at ' . $this->userDate->format($now), Logger::ERROR);

        //$e = new \Exception; // for debugging only
        //Logger::log($e->getTraceAsString(), Logger::ERROR);

        $this->prefixes = $this->robotConfig['prefixes'] ?? '/!';

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
        $session = __DIR__ . '/' . $eh->getSessionName();
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
        Logger::log("Destructing BaseEventHandler! Reason:'$reason'  Session:'$session'", Logger::ERROR);
    }

    public function onStart(): \Generator
    {
        $this->canExecute = false;
        $this->stopReason = "UNKNOWN";

        $record = \Launch::updateLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME);
        $record = \Launch::floatToDate($record, $this->userDate);
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
        $this->mp = $mp;
        $mpVersion = MTProto::RELEASE . ' (' . MTProto::V . ', ' . Magic::$revision . ')';
        Logger::log("MadelineProto version: '$mpVersion'", Logger::ERROR);

        $loopNames = $this->getLoopNames(); // $this->robotConfig['mp'][0]['loops'];
        $this->loops = [];
        foreach ($loopNames as $loopName) {
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
            if (method_exists($newClass, 'onStart')) {
                Logger::log("Loop plugin '$className' onStart method invoked!", Logger::ERROR);
                yield $newClass->onStart();
            } else {
                Logger::log("Lopp plugin '$className'  has no onStart method!", Logger::ERROR);
            }
            $newClass->start();
            $this->loops[strtolower($loopName)] = $newClass;
        }
    }

    public function onAny(array $update): \Generator
    {
        $verb = $this->possiblyVerb($update, $this->getPrefixes(), 30);
        //$msgFront = substr($update['message']['message'] ?? '', 0, 30);
        //$this->logger("PossibleVerb: '$verb', newMessage: " . ($this->newMessage($update) ? 'yes' : 'no') . "  msgType: {$update['_']}  msg: '$msgFront'", Logger::ERROR);
        //$isNew = floatval($update['message']['date'] ?? 0) >= $this->getScriptStarted();
        if (!$this->canExecute && $verb !== '' && $this->newMessage($update)) {
            $this->newMessage($update);
            $this->canExecute = true;
            $this->logger('Command-Processing engine started at ' . $this->formatTime(), Logger::ERROR);
        }
        //$vars = ['verb' => $verb];
        if ($verb !== '') {
            $msgDate   = $this->formatTime(floatval($update['message']['date']));
            $nowDate   = $this->formatTime(\microtime(true));
            $startDate = $this->formatTime($this->getScriptStarted());
            $age = $this->canExecute() ? 'new' : 'old';
            $this->logger("$age verb:$verb, msg:$msgDate, start:$startDate, now:$nowDate", Logger::ERROR);
            if ($age === 'new') {
                //$vars = computeVars($update, $this);
            }
        }
        $vars = computeVars($update, $this);

        foreach ($this->handlers as $handlerName => $handler) {
            $processed = yield ($handler($update, $vars, $this));
        }
    }

    public function getRobotConfig(): array
    {
        return $this->robotConfig;
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
        return $this->robotConfig['adminIds'] ?? [];
    }
    public function getOfficeId(): ?int
    {
        return $this->officeConfig['officeid'] ?? null;
    }

    public function getUserDate(): \UserDate
    {
        return $this->userDate;
    }
    public function getZone(): string
    {
        return $this->userDate->getZone();
    }
    function formatTime(float $microtime = null, string $format = 'H:i:s.v'): string
    {
        $microtime = $microtime ?? \microtime(true);
        return $microtime < 100 ? 'UNAVAILABLE' : ($this->userDate->format($microtime, $format));
    }

    public function canExecute(): bool
    {
        return $this->canExecute ?? false;
    }

    public function getStopReason(): string
    {
        return $this->stopReason ?? 'UNKNOWN';
    }
    public function setStopReason(string $stopReason): void
    {
        $this->stopReason = $stopReason;
    }

    function getEditMessage(): bool
    {
        return $this->robotConfig['edit'] ?? true;
    }

    function getSessionName(): string
    {
        return $this->robotConfig['mp'][0]['session'] ?? 'madeline.madeline';
    }

    function getLoopNames(): array
    {
        return $this->robotConfig['mp'][0]['loops'] ?? [];
    }

    function getHandlerNames(): array
    {
        return $this->robotConfig['mp'][0]['handlers'] ?? [];
    }

    function getPrefixes(): string
    {
        return $this->prefixes;
    }

    function getScriptStarted(): float
    {
        return SCRIPT_START_TIME; // $this->scriptStarted;
    }
    function getHandlerConstructed(): float
    {
        return $this->handlerConstructed;
    }

    function getHandlerUnserialized(): float
    {
        return $this->handlerUnserialized;
    }

    function getSession(): string
    {
        return \basename($this->session);
    }

    function newMessage(array $update): bool
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

    public function getLoopState(string $loopName): bool
    {
        $state = $this->__get('loop_state');
        return $state ?? false;
    }
    public function setLoopState(string $loopName, bool $loopState): void
    {
        $this->__set('loop_state', $loopState);
    }

    public function getSessionCreation(): \Generator // float
    {
        $filepath = CREATION_FILE;
        $strTime  = yield \Amp\File\get($filepath);
        if ($strTime === null || $strTime === '') {
            $microTime = $this->getScriptStarted();
            $strTime   = strval(intval(round($microTime * 1000000)));
            yield \Amp\File\put($filepath, $strTime);
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
}
