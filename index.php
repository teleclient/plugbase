<?php

declare(strict_types=1);

//if (\file_exists('vendor/autoload.php')) {
//    include 'vendor/autoload.php';
//} else {
if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
include 'madeline.php';
//}
require_once 'BuiltinPlugin.php';
require_once    'YourPlugin.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    private BuiltinPlugin $builtinPlugin;
    private    YourPlugin $yourPlugin;

    function __construct(\danog\MadelineProto\APIWrapper $api)
    {
        $this->builtinPlugin = new BuiltinPlugin($this);
        $this->yourPlugin    = new    YourPlugin($this);
    }
    public function onStart(): \Generator
    {
        yield $this->builtinPlugin->onStart();
        yield $this->yourPlugin->onStart();
    }
    public function onAny(array $update): \Generator
    {
        yield $this->builtinPlugin($update);
        yield $this->yourPlugin($update);
    }
}

$settings['app_info']['api_id']   = 904912;
$settings['app_info']['api_hash'] = "8208f08eefc502bedea8b5d437be898e";
$settings['app_info']['app_version'] = "DIALOG V0.1.0";
$settings['logger']['logger'] = danog\MadelineProto\Logger::FILE_LOGGER;
$settings['logger']['logger_level'] = danog\MadelineProto\Logger::ERROR;

$mp = new \danog\MadelineProto\API('madeline.madeline', $settings);
$mp->startAndLoop(EventHandler::class);

echo ('Bye, bye!');
