<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Magic;

class FilteredLogger
{
    private Logger $filteredLog;
    private Logger $callbackLog;

    public function __construct(array &$robotConfig, $index)
    {
        Magic::classExists();
        \error_clear_last();

        $logger = $robotConfig['mp'][$index]['settings']['logger'] ?? [];

        $dest  = $logger['logger']       ?? Logger::FILE_LOGGER;
        $name  = $logger['logger_param'] ?? "MadelineProto.log";
        $level = $logger['logger_level'] ?? Logger::NOTICE;
        $size  = $logger['max_size']     ?? 100 * 1024 * 1024;

        $settings = ['logger' => [
            'logger'       => $dest,
            'logger_param' => $name,
            'logger_level' => $level,
            'max_size'     => $size
        ]];
        $this->filteredLog = Logger::getLoggerFromSettings($settings);

        Logger::$default = null;

        $settings = ['logger' => [
            'logger'       => Logger::CALLABLE_LOGGER,
            'logger_param' => $this,
            'logger_level' => $level,
        ]];
        $robotConfig['mp'][$index]['settings']['logger'] = $settings;

        $this->callbackLog = Logger::getLoggerFromSettings($settings);
    }

    public function __invoke($entry, int $level): void
    {
        //echo ("Hi!\n");
        if (\is_array($entry)) {
            $entry = toJSON($entry);
        } elseif (\is_object($entry)) {
            // Parse the regular or Throwable Object
            $class = get_class($entry);
            $type = strEndsWith($class, 'Exception') ? 'EXCEPTION' : 'OBJECT';
            $entry = "'$class' $type: " . (string)$entry;
        } elseif (!\is_string($entry)) {
            // Anything but string
            $entry = "?????: " . (string)$entry;
        } else if (substr($entry, 0, 11) === 'Rejecting: ') {
            if (substr($entry, 11, 32) === 'Telegram returned an RPC error: ') {
                //Parse the RPCException
                $pattern = "/^(.*) \((\d*)\) *\((.*)\), caused by (.*)<br>.*\['(.*)'\]<br>/s";
                preg_match($pattern, substr($entry, 43), $matches);
                $desc   = $matches[1];
                $code   = $matches[2];
                $key    = $matches[3];
                $method = $matches[5];
                $error = "Telegram RPC Error: {method:'$method', code:'$code', key:'$key', desc:'$desc'}";
                $this->filteredLog->logger(levelDescr(1) . ": " . $error, 1);
            } else {
                // Parse the Telegram request
                $method = substr($entry, 11);
                $entry = "Rejecting: method:'$method'";
            }
            $level = 1;
        } else {
            // Regular string entry
        }
        $this->filteredLog->logger(levelDescr($level) . ": " . $entry, 1);
        return;
    }

    function levelDescr(int $level): string
    {
        //static $desc = ['FATAL_ERROR', 'ERROR', 'WARNING', 'NOTICE', 'VERBOSE', 'ULTRA_VERBOSE'];
        static $desc = ['F', 'E', 'W', 'N', 'V', 'U'];
        return $desc[$level];
    }

    function exceptionClass(string $entry): ?string
    {
        $loc = strpos($entry, 'Exception in ');
        if ($loc !== false) {
            $name = substr($entry, 0, $loc + 9);
            //echo ("Exception: '$name'" . PHP_EOL);
            return $name;
        }
        return null;
    }

    /*
    public function getRobotSettings(array $robotConfig, int $index): array
    {
        $settings = $robotConfig['mp'][$index]['settings'] ?? [];

        $level = $settings['logger']['logger_level'] ?? Logger::NOTICE;
        unset($settings['logger']);
        $settings['logger'] = [
            'logger'       => Logger::CALLABLE_LOGGER,
            'logger_param' => $this,
            'logger_level' => $level,
        ];
        return $settings;
    }
    */
}

/*
\sprintf(
    'Telegram returned an RPC error: %s (%s), caused by %s:%s%sTL trace:', 
    self::localizeMessage($this->caller, $this->code, $this->message) . " ({$this->code})", 
    $this->rpc, 
    $this->file, 
    $this->line . PHP_EOL, 
    \danog\MadelineProto\Magic::$revision . PHP_EOL . PHP_EOL
) . PHP_EOL . $this->getTLTrace() . PHP_EOL;

if (\strpos($e->rpc, 'FLOOD_WAIT_') === 0 || $e->rpc === 'AUTH_KEY_UNREGISTERED' || $e->rpc === 'USERNAME_INVALID') 


Error : Telegram returned an RPC error: You are spamreported, you can't do this (400) (PEER_FLOOD), caused by phar:///home/erfan/public_html/1/madeline.phar/vendor/danog/madelineproto/src/danog/MadelineProto/MTProtoSession/ResponseHandler.php:460<br>
Revision: 979d7575fc4bdee37c10b3d17fc2be90c3fed88c (AN UPDATE IS REQUIRED)<br>
<br>
TL trace:<br>
['messages.forwardMessages']<br><br>
CallHandler.php(52): methodCallAsyncRead("messages.forwardMessages",{"from_peer":-1001247063066,"to_peer":{"_":"peerUser","user_id":1240392196},"id":[17072]},{"apifactory":true,"datacenter":4})<br><br>
AbstractAPIFactory.php(165): methodCallAsyncRead("messages.forwardMessages",{"from_peer":-1001247063066,"to_peer":{"_":"peerUser","user_id":1240392196},"id":[17072]},{"apifactory":true,"datacenter":4})<br><br>
index.php(373):      __call_async()<br><br>
index.php(88):       onUpdateNewMessage()<br><br>
onUpdateNewChannelMessage()<br><br>
<br><br>
Previous TL trace:<br><br>
['messages.forwardMessages']<br><br>
ResponseHandler.php(86): handleResponse()<br><br>
Driver.php(102):     handleMessages("tp",null)<br><br>
Driver.php(61):      tick()<br><br>
Loop.php(87):        run()<br><br>
Tools.php(303):      run({})<br><br>
AbstractAPIFactory.php(120): wait({})<br><br>
InternalDoc.php(5561): __call("loop",[null,[]])<br><br>
index.php(819):      loop()<br>
*/