<?php

declare(strict_types=1);

use tgseclib\Math\BigInteger\Engines\PHP32;

define('SCRIPT_INFO', 'BASE_P V0.1.0'); // <== Do not change!

require_once 'functions.php';
includeMadeline();
$robotConfig = include('config.php');
require_once 'plugins/BuiltinPlugin.php';
require_once    'plugins/YourPlugin.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    private BuiltinPlugin $builtinPlugin;
    private    YourPlugin $yourPlugin;
    private int    $robotId;
    private object $robotConfig;

    function __construct(\danog\MadelineProto\APIWrapper $api)
    {
        $this->builtinPlugin = new BuiltinPlugin($this);
        $this->yourPlugin    = new    YourPlugin($this);
    }
    public function onStart(): \Generator
    {
        //$resp = yield $this->messages->sendMessage(['message' => 'Hi', 'peer' => $this->getRobotId]);
        //yield $this->echo('onStart executed.' . PHP_EOL);
        yield $this->builtinPlugin->onStart();
        yield $this->yourPlugin->onStart();
    }
    public function onAny(array $update): \Generator
    {
        yield $this->builtinPlugin($update);
        yield $this->yourPlugin($update);
    }

    public function setSelf(array $self)
    {
        $this->robotId = $self['id'];
    }
    public function getRobotId(array $self): int
    {
        return $this->robotId;
    }
    public function setRobotConfig(object $config)
    {
        $this->robotConfig = $config;
    }
    public function getRobotConfig(): object
    {
        return $this->robotConfig;
    }
}

$session  = $robotConfig->mp[0]['session'];
$settings = $robotConfig->mp[0]['settings'];
$mp = new \danog\MadelineProto\API($session, $settings);

echo ("Authorization State: " . authorizationStateDesc(authorizationState($mp)) . PHP_EOL);
echo ("Is Authorized: " . ($mp->hasAllAuth() ? 'true' : 'false') . PHP_EOL);

safeStartAndLoop($mp, EventHandler::class, $robotConfig);

echo ('Bye, bye!');
