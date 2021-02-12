<?php

declare(strict_types=1);

use danog\madelineproto\Logger;

define('SCRIPT_START', microtime(true));
define('SCRIPT_INFO',  'BASE_P V0.1.0'); // <== Do not change!
require_once 'functions.php';
includeMadeline('phar');
$robotConfig = include('config.php');
$userDate = new \UserDate($robotConfig['zone']);
echo ('Script started at: ' . $userDate->format(SCRIPT_START) . PHP_EOL);

require_once         'plugins/Plugin.php';
require_once 'plugins/AbstractPlugin.php';
require_once  'plugins/BuiltinPlugin.php';
require_once     'plugins/YourPlugin.php';

class BaseEventHandler extends \danog\MadelineProto\EventHandler
{
    private BuiltinPlugin $builtinPlugin;
    private    YourPlugin $yourPlugin;

    private float $sessionCreated;
    private float $scriptStarted;
    private float $handlerUnserialized; // $sessionRestarted?

    private array    $robotConfig;
    private int      $robotId;
    private string   $robotName;
    private UserDate $userDate;
    private bool     $canExecute;
    private string   $stopReason;

    function __construct(\danog\MadelineProto\APIWrapper $api)
    {
        parent::__construct($api);
        yield $this->eh->echo("EventHandler::onStart executed!" . PHP_EOL);

        $now = microtime(true);
        $this->sessionCreated      = $now;
        $this->handlerUnserialized = $now;
        $this->scriptStarted       = SCRIPT_START;

        $this->canExecute          = false;
        $this->stopReason          = "UNKNOWN";

        //$userDate = new UserDate($this->robotConfig['zone']);
        //echo ('EventHandler Created at: ' . $userDate->milli($now) . PHP_EOL);

        $this->builtinPlugin = new BuiltinPlugin($this);
        $this->yourPlugin    = new    YourPlugin($this);
    }

    public function onStart(): \Generator
    {
        //yield $this->sleep(2);
        yield $this->echo('EventHandler::onStart executed.' . PHP_EOL);
        yield $this->logger('EventHandler::onStart executed.', Logger::ERROR);
        //$this->userDate = new UserDate($this->robotConfig['zone']);
        //$e = new \Exception;
        //yield $this->echo($e->getTraceAsString($e) . PHP_EOL);
        //yield $this->echo('onStart executed.' . PHP_EOL);

        if (method_exists('BuiltinPlugin', 'onStart')) {
            yield $this->builtinPlugin->onStart();
        }
        if (method_exists('YouPlugin', 'onStart')) {
            yield $this->yourPlugin->onStart();
        }
    }

    public function onAny(array $update): \Generator
    {
        if (
            !$this->canExecute &&
            $update['_'] === 'updateNewMessage' &&
            $update['message']['date'] > intval(SCRIPT_START)
        ) {
            $this->canExecute = true;
            yield $this->logger('Command-Processing engine started at ' . date('d H:i:s'), Logger::ERROR);
        }

        $vars = computeVars($update, $this);

        yield $this->builtinPlugin->handleEvent($update, $vars);
        yield $this->yourPlugin->handleEvent($update, $vars);
    }

    //public function __sleep()
    //{
    //    //echo ('EventHandler Sleep called.' . PHP_EOL);
    //    return ['sessionCreated', 'robotId', 'robotConfig'];
    //}
    public function __wakeup()
    {
        $this->scriptStarted       = SCRIPT_START;
        $this->handlerUnserialized = microtime(true);
        $this->canExecute          = false;
        $this->stopReason          = "UNKNOWN";

        echo ('EventHandler restarted.' . PHP_EOL);
        //$this->builtinPlugin = new BuiltinPlugin($this);
        //$this->yourPlugin    = new    YourPlugin($this);
    }

    public function setRobotConfig(array $robotConfig)
    {
        $this->robotConfig = $robotConfig;
    }
    public function getRobotConfig(): array
    {
        return $this->robotConfig;
    }

    public function setSelf(array $self)
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

    public function setUserDate(\UserDate $userDate): void
    {
        $this->userDate = $userDate;
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
}

$session  = $robotConfig['mp'][0]['session'];
$settings = $robotConfig['mp'][0]['settings'];
$mp = new \danog\MadelineProto\API($session, $settings);
$authState = authorizationState($mp);
echo ("Authorization State: " . authorizationStateDesc($authState) . PHP_EOL);
if ($authState === 4) {
    echo (PHP_EOL . "Invalid App, or the Session is corrupted!" . PHP_EOL . PHP_EOL);
}
echo ("Is Authorized: " . ($mp->hasAllAuth() ? 'true' : 'false') . PHP_EOL);

safeStartAndLoop($mp, BaseEventHandler::class, $robotConfig);
$mp->loop();
echo ('Bye, bye!');


function robotName(array $user): array
{
    $name = strval($user['id']);
    if (isset($user['username'])) {
        $name = $user['username'];
    } elseif (isset($user['first_name'])) {
        $name = $user['first_name'];
    } elseif (isset($user['last_name'])) {
        $name = $user['last_name'];
    }
    return $name;
}
