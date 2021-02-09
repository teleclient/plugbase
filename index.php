<?php

declare(strict_types=1);

define('SCRIPT_START', microtime(true));
define('SCRIPT_INFO',  'BASE_P V0.1.0'); // <== Do not change!
require_once 'functions.php';
includeMadeline('composer');
$robotConfig = include('config.php');
$userDate = new \UserDate($robotConfig['zone']);
echo ('Script started at:' . $userDate->milli(SCRIPT_START) . PHP_EOL);

require_once 'plugins/BuiltinPlugin.php';
require_once    'plugins/YourPlugin.php';

class BaseEventHandler extends \danog\MadelineProto\EventHandler
{
    private BuiltinPlugin $builtinPlugin;
    private    YourPlugin $yourPlugin;

    private int   $handlerConstructed;
    private int   $handlerUnserialized;
    private int   $robotId;
    //private int   $officeId;
    //private array $adminIds;
    private array $robotConfig;

    function __construct(\danog\MadelineProto\APIWrapper $api)
    {
        $this->handlerConstructed  = microtime(true);
        $this->handlerUnserialized = $this->handlerConstructed;
        echo ('EventHandler Created at: ' . $this->handlerConstructed . PHP_EOL);

        $this->builtinPlugin = new BuiltinPlugin($this);
        $this->yourPlugin    = new    YourPlugin($this);
    }
    public function onStart(): \Generator
    {
        yield $this->sleep(2);
        yield $this->echo('onStart executed.' . PHP_EOL);
        yield $this->logger('onStart executed.');
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
        yield $this->builtinPlugin->handleEvent($update);
        yield $this->yourPlugin->handleEvent($update);
    }

    public function __sleep()
    {
        //echo ('EventHandler Sleep called.' . PHP_EOL);
        return ['handlerConstructed', 'robotId', 'robotConfig'];
    }
    public function __wakeup()
    {
        $this->handlerUnserialized = microtime(true);
        echo ('EventHandler restarted called at: ' . PHP_EOL);
        $this->builtinPlugin = new BuiltinPlugin($this);
        $this->yourPlugin    = new    YourPlugin($this);
    }

    public function setSelf(array $self)
    {
        $this->robotId = $self['id'];
    }
    public function getRobotId(array $self): int
    {
        return $this->robotId;
    }
    public function setRobotConfig(array $robotConfig)
    {
        $this->robotConfig = $robotConfig;
    }
    public function getRobotConfig(): array
    {
        return $this->robotConfig;
    }

    public function getAdminIds(): array
    {
        return $this->robotConfig['adminIds'] ?? [];
    }
    public function getOfficeId(): ?int
    {
        return $this->officeConfig['officeid'] ?? null;
    }

    public function canExecute(): bool
    {
        return true;
    }
}

$session  = $robotConfig['mp'][0]['session'];
$settings = $robotConfig['mp'][0]['settings'];
$mp = new \danog\MadelineProto\API($session, $settings);
$authState = authorizationState($mp);
echo ("Authorization State: " . authorizationStateDesc($authState) . PHP_EOL);
if ($authState === 4) {
    echo (PHP_EOL . "Invalid App, or the Session is corrupted!" . PHP_EOL . PHP_EOL);
    //throw new \ErrorException("Invalid App, or Session is corrupted!");
}
echo ("Is Authorized: " . ($mp->hasAllAuth() ? 'true' : 'false') . PHP_EOL);

safeStartAndLoop($mp, BaseEventHandler::class, $robotConfig);
//$mp->startAndLoop(BaseEventHandler::class);
//myStartAndLoop($mp, BaseEventHandler::class);
//$mp->loop(function () use ($mp) {
//    yield $mp->start();
//    yield $mp->setEventHandler(BaseEventHandler::class);
//});
$mp->loop();
echo ('Bye, bye!');


//$vars['execute']  = $eh->canExecute();
