<?php

declare(strict_types=1);

use danog\madelineproto\Logger;

initPhp();
define('SCRIPT_START', microtime(true));
define('SCRIPT_INFO',  'BASE_P V0.1.0'); // <== Do not change!
require_once 'functions.php';
includeMadeline('phar');
define('ROBOT_CONFIG', include('config.php'));
$userDate = new \UserDate(ROBOT_CONFIG['zone']);
error_log('Script started at: ' . $userDate->format(SCRIPT_START) . '<br>');

require_once         'plugins/Plugin.php';
require_once 'plugins/AbstractPlugin.php';
require_once  'plugins/BuiltinPlugin.php';
require_once     'plugins/YourPlugin.php';

class BaseEventHandler extends \danog\MadelineProto\EventHandler
{
    //use \danog\Serializable;

    static array $robotConfig1;

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

    function __construct(\danog\MadelineProto\APIWrapper $apiWrapper)
    {
        parent::__construct($apiWrapper);
        Logger::log(toJSON(ROBOT_CONFIG));
        $now = microtime(true);

        sleep(2);
        $userDate = new \UserDate(ROBOT_CONFIG['zone']);
        Logger::Log("EventHandler instantiated at " . $userDate->format($now), Logger::ERROR);

        $this->sessionCreated      = $now;
        $this->handlerUnserialized = $now;
        $this->scriptStarted       = SCRIPT_START;

        $this->canExecute          = false;
        $this->stopReason          = "UNKNOWN";

        $this->builtinPlugin = new BuiltinPlugin($this);
        $this->yourPlugin    = new    YourPlugin($this);
    }

    public function __wakeup()
    {
        $this->scriptStarted  = SCRIPT_START;
        $now = microtime(true);
        $this->handlerUnserialized = $now;
        $userDate = new \UserDate(ROBOT_CONFIG['zone']);
        Logger::log('EventHandler unserialized at ' . $userDate->format($now), Logger::ERROR);

        $this->canExecute = false;
        $this->stopReason = "UNKNOWN";
        //Logger::log(toJSON(ROBOT_CONFIG), Logger::ERROR);
    }

    public function onStart(): \Generator
    {
        $robotConfig = $this->__get('configuration');
        if ($robotConfig) {
            echo (toJSON($robotConfig) . PHP_EOL);
        }
        $start = $this->formatTime(microtime(true));
        yield $this->logger("EventHandler::onStart executed at $start", Logger::ERROR);
        //$this->userDate = new UserDate($this->robotConfig['zone']);
        //$e = new \Exception;
        //yield $this->echo($e->getTraceAsString($e) . PHP_EOL);

        if (method_exists('BuiltinPlugin', 'onStart')) {
            yield $this->builtinPlugin->onStart($this);
        }
        if (method_exists('YouPlugin', 'onStart')) {
            yield $this->yourPlugin->onStart($this);
        }
    }

    public function onAny(array $update): \Generator
    {
        if (
            !$this->canExecute && $this->newMessage($update)
            //oneOf($update, 'NewMessage|EditMessage') &&

        ) {
            $this->canExecute = true;
            yield $this->logger('Command-Processing engine started at ' . date('d H:i:s'), Logger::ERROR);
        }

        $vars = computeVars($update, $this);

        $processed = yield ($this->builtinPlugin)($update, $vars, $this);
        $processed = yield ($this->yourPlugin)($update, $vars, $this);
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

$session  = ROBOT_CONFIG['mp'][0]['session'];
$settings = ROBOT_CONFIG['mp'][0]['settings'];
$mp = new \danog\MadelineProto\API($session, $settings);
//$prevSettings = $mp->getSettings();
$mp->updateSettings(['logger_level' => \danog\MadelineProto\Logger::NOTICE]);
$authState = authorizationState($mp);
error_log("<br>Authorization State: " . authorizationStateDesc($authState) . '<br>' . PHP_EOL);
if ($authState === 4) {
    echo (PHP_EOL . "Invalid App, or the Session is corrupted!<br>" . PHP_EOL . PHP_EOL);
}
error_log("Is Authorized: " . ($mp->hasAllAuth() ? 'true' : 'false') . '<br>' . PHP_EOL);
$mp->__set('configuration', ROBOT_CONFIG);

safeStartAndLoop($mp, BaseEventHandler::class, ROBOT_CONFIG);

echo ('Bye, bye!<br>' . PHP_EOL);
error_log('Bye, bye!<br>');
