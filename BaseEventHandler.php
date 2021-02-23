<?php

declare(strict_types=1);

use danog\madelineproto\Logger;

require_once 'Plugin.php';
require_once 'AbstractPlugin.php';
require_once 'plugins/BuiltinPlugin.php';
require_once 'plugins/YourPlugin.php';

class BaseEventHandler extends \danog\MadelineProto\EventHandler
{
    private BuiltinPlugin $builtinPlugin;
    private    YourPlugin $yourPlugin;

    private float $sessionCreated;
    private float $scriptStarted;
    private float $handlerUnserialized;

    private array    $robotConfig;
    private int      $robotId;
    private string   $robotName;
    private UserDate $userDate;
    private bool     $canExecute;
    private string   $stopReason;

    function __construct(\danog\MadelineProto\APIWrapper $apiWrapper)
    {
        parent::__construct($apiWrapper);
        $this->robotConfig = $GLOBALS['robotConfig'];

        $now = microtime(true);
        $record = \Launch::updateLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME);

        $this->sessionCreated      = $now;
        $this->handlerUnserialized = $now;
        $this->scriptStarted       = SCRIPT_START_TIME;

        $this->userDate    = new \UserDate($this->robotConfig['zone']);
        $this->canExecute  = false;
        $this->stopReason  = "UNKNOWN";

        Logger::log(toJSON($this->robotConfig));
        Logger::Log("EventHandler instantiated at " . $this->userDate->format($now), Logger::ERROR);
        Logger::log("EventHandler Update Run Record: " . toJSON($record), Logger::ERROR);

        $this->builtinPlugin = new BuiltinPlugin($this);
        $this->yourPlugin    = new    YourPlugin($this);
    }

    public function __wakeup()
    {
        $this->scriptStarted  = SCRIPT_START_TIME;
        $now = microtime(true);
        $this->handlerUnserialized = $now;
        $userDate = new \UserDate($this->robotConfig['zone']);
        Logger::log('EventHandler unserialized at ' . $userDate->format($now), Logger::ERROR);

        $this->canExecute = false;
        $this->stopReason = "UNKNOWN";
    }

    public function onStart(): \Generator
    {
        $dateStr = $this->formatTime($this->getHandlerUnserialized());
        yield $this->logger("Event Handler instantiated at $dateStr!", Logger::ERROR);

        $start = $this->formatTime(microtime(true));
        yield $this->logger("EventHandler::onStart executed at $start", Logger::ERROR);

        $self = yield $this->getSelf();
        yield $this->setSelf($self);

        if (method_exists('BuiltinPlugin', 'onStart')) {
            yield $this->builtinPlugin->onStart($this);
        }
        if (method_exists('YouPlugin', 'onStart')) {
            yield $this->yourPlugin->onStart($this);
        }
    }

    public function onAny(array $update): \Generator
    {
        if (!$this->canExecute && $this->newMessage($update)) {
            $this->canExecute = true;
            yield $this->logger('Command-Processing engine started at ' . $this->formatTime(), Logger::ERROR);
        }

        $vars = computeVars($update, $this);

        $processed = yield ($this->builtinPlugin)($update, $vars, $this);
        $processed = yield ($this->yourPlugin)($update, $vars, $this);
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
        return $this->userDate->format($microtime ?? \microtime(true), $format);
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
        return $this->robotConfig['mp']['0']['session'];
    }

    function getPrefixes(): string
    {
        return $this->getRobotConfig()['prefixes'] ?? '/!';
    }

    function getSessionCreated(): float
    {
        return $this->sessionCreated ?? 0;
    }

    function getScriptStarted(): float
    {
        return $this->scriptStarted;
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
        $msgDate    = $update['message']['date'] ?? 0;
        $newMessage = $msgDate >= intval($this->scriptStarted);
        return $newMessage;
    }
}
