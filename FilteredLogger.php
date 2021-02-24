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
        unset($robotConfig['mp'][$index]['settings']['logger']);
        $robotConfig['mp'][$index]['settings']['logger']['logger'] = Logger::CALLABLE_LOGGER;
        $robotConfig['mp'][$index]['settings']['logger']['logger_param'] = $this;
        $robotConfig['mp'][$index]['settings']['logger']['logger_level'] = $level;

        $this->callbackLog = Logger::getLoggerFromSettings($settings);
    }

    public function __invoke($entry, int $level): void
    {
        try {
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
                    $this->rpcException($entry, $level);
                    //Parse the RPCException
                    $pattern = "/^(.*) \((\d*)\) *\((.*)\), caused by (.*)<br>.*\['(.*)'\]<br>/s";
                    $pattern = "/^(.*) \((\d*)\) *\((.*)\), caused by (.*):(\d*).*\n\n\n.*\n\['(.*)'].*/s";
                    $text = substr($entry, 43);
                    //$this->filteredLog->logger($this->levelDescr(1) . ": " . $pattern, 1);
                    //$this->filteredLog->logger($this->levelDescr(1) . ": " . $text, 1);
                    preg_match($pattern, $text, $matches);
                    $desc   = $matches[1] ?? '__desc__';
                    $code   = $matches[2] ?? '__code__';
                    $key    = $matches[3] ?? '__key__';
                    $method = $matches[6] ?? '__method__';
                    $error = "Telegram RPC Error: {method:'$method', code:'$code', key:'$key', desc:'$desc'}";
                    $this->filteredLog->logger($this->levelDescr(1) . ": " . $error, 1);
                } else {
                    // Parse the Telegram request
                    $method = substr($entry, 11);
                    $entry = "Rejecting: method:'$method'";
                }
                $level = 1;
            } else {
                // Regular string entry
            }
        } catch (Throwable $e) {
            throw new Exception($e->getMessage());
        }
        $this->filteredLog->logger($this->levelDescr($level) . ": " . $entry, 1);
        return;
    }

    private function rpcException(string $entry, int $level): void
    {
    }

    private function levelDescr(int $level): string
    {
        //static $desc = ['FATAL_ERROR', 'ERROR', 'WARNING', 'NOTICE', 'VERBOSE', 'ULTRA_VERBOSE'];
        static $desc = ['F', 'E', 'W', 'N', 'V', 'U'];
        return $desc[$level];
    }
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
*/

//if (\strpos($e->rpc, 'FLOOD_WAIT_') === 0 || $e->rpc === 'AUTH_KEY_UNREGISTERED' || $e->rpc === 'USERNAME_INVALID') 

/*
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

/*
Rejecting: Telegram returned an RPC error: The authorization key has expired (401) (AUTH_KEY_UNREGISTERED), caused by /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/MTProtoSession/ResponseHandler.php:403


TL trace:
['users.getUsers']
danog\MadelineProto\MTProtoSession\{closure}()
Coroutine.php(116): 	send()
Placeholder.php(46):	danog\MadelineProto\{closure}()
Coroutine.php(155): 	onResolve()
Tools.php(418):     	__construct()
ResponseHandler.php(404):	callFork()
ResponseHandler.php(88):	handleResponse()
Driver.php(119):    	handleMessages()
Driver.php(72):     	tick()
Loop.php(95):       	run()
Tools.php(305):     	run()
AbstractAPIFactory.php(126):	wait()
InternalDoc.php(5897):	__call()
index.php(169):     	loop()
[0m
*/


/*
'danog\MadelineProto\NothingInTheSocketException' EXCEPTION: danog\MadelineProto\NothingInTheSocketException in /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Stream/Common/BufferedRawStream.php:180
Stack trace:
#0 [internal function]: danog\MadelineProto\Stream\Common\BufferedRawStream->bufferReadGenerator()
#1 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Coroutine.php(116): Generator->send()
#2 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Internal/Placeholder.php(149): danog\MadelineProto\Coroutine->danog\MadelineProto\{closure}()
#3 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Deferred.php(52): class@anonymous->resolve()
#4 /home/esfand/devphp/teleclient_pl/vendor/amphp/byte-stream/lib/ResourceInputStream.php(101): Amp\Deferred->resolve()
#5 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop/NativeDriver.php(321): Amp\ByteStream\ResourceInputStream::Amp\ByteStream\{closure}()
#6 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop/NativeDriver.php(127): Amp\Loop\NativeDriver->selectStreams()
#7 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop/Driver.php(138): Amp\Loop\NativeDriver->dispatch()
#8 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop/Driver.php(72): Amp\Loop\Driver->tick()
#9 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop.php(95): Amp\Loop\Driver->run()
#10 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Tools.php(305): Amp\Loop::run()
#11 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/AbstractAPIFactory.php(126): danog\MadelineProto\Tools::wait()
#12 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/InternalDoc.php(5953): danog\MadelineProto\AbstractAPIFactory->__call()
#13 /home/esfand/devphp/teleclient_pl/index.php(168): danog\MadelineProto\InternalDoc->start()
#14 [internal function]: {closure}()
#15 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Coroutine.php(70): Generator->current()
#16 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Coroutine.php(135): danog\MadelineProto\Coroutine->__construct()
#17 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Internal/ResolutionQueue.php(70): danog\MadelineProto\Coroutine->danog\MadelineProto\{closure}()
#18 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Internal/Placeholder.php(149): Amp\Internal\ResolutionQueue->__invoke()
#19 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Coroutine.php(127): danog\MadelineProto\Coroutine->resolve()
#20 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Internal/ResolutionQueue.php(70): danog\MadelineProto\Coroutine->danog\MadelineProto\{closure}()
#21 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Internal/Placeholder.php(149): Amp\Internal\ResolutionQueue->__invoke()
#22 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Coroutine.php(127): danog\MadelineProto\Coroutine->resolve()
#23 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Failure.php(33): danog\MadelineProto\Coroutine->danog\MadelineProto\{closure}()
#24 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Internal/Placeholder.php(143): Amp\Failure->onResolve()
#25 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Internal/Placeholder.php(177): class@anonymous->resolve()
#26 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Deferred.php(65): class@anonymous->fail()
#27 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/MTProtoSession/ResponseHandler.php(295): Amp\Deferred->fail()
#28 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop/Driver.php(119): danog\MadelineProto\MTProtoSession\Session->danog\MadelineProto\MTProtoSession\{closure}()
#29 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop/Driver.php(72): Amp\Loop\Driver->tick()
#30 /home/esfand/devphp/teleclient_pl/vendor/amphp/amp/lib/Loop.php(95): Amp\Loop\Driver->run()
#31 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/Tools.php(305): Amp\Loop::run()
#32 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/AbstractAPIFactory.php(126): danog\MadelineProto\Tools::wait()
#33 /home/esfand/devphp/teleclient_pl/vendor/danog/madelineproto/src/danog/MadelineProto/InternalDoc.php(5897): danog\MadelineProto\AbstractAPIFactory->__call()
#34 /home/esfand/devphp/teleclient_pl/index.php(169): danog\MadelineProto\InternalDoc->loop()
#35 {main}[0m
[97;1;41mFilteredLogger: 	E: Got nothing in the socket in DC 1.0, reconnecting...[0m
*/

/*
//public static 
$descriptions = [
    'RPC_MCGET_FAIL' => 'Telegram is having internal issues, please try again later.', 
    'RPC_CALL_FAIL' => 'Telegram is having internal issues, please try again later.', 
    'USER_PRIVACY_RESTRICTED' => "The user's privacy settings do not allow you to do this", 
    'CHANNEL_PRIVATE' => "You haven't joined this channel/supergroup", 
    'USER_IS_BOT' => "Bots can't send messages to other bots", 
    'BOT_METHOD_INVALID' => 'This method cannot be run by a bot', 
    'PHONE_CODE_EXPIRED' => 'The phone code you provided has expired, this may happen if it was sent to any chat on telegram (if the code is sent through a telegram chat (not the official account) to avoid it append or prepend to the code some chars)', 
    'USERNAME_INVALID' => 'The provided username is not valid', 
    'ACCESS_TOKEN_INVALID' => 'The provided token is not valid', 
    'ACTIVE_USER_REQUIRED' => 'The method is only available to already activated users', 
    'FIRSTNAME_INVALID' => 'The first name is invalid', 
    'LASTNAME_INVALID' => 'The last name is invalid', 
    'PHONE_NUMBER_INVALID' => 'The phone number is invalid', 
    'PHONE_CODE_HASH_EMPTY' => 'phone_code_hash is missing', 
    'PHONE_CODE_EMPTY' => 'phone_code is missing', 
    'PHONE_CODE_EXPIRED' => 'The confirmation code has expired', 
    'API_ID_INVALID' => 'The api_id/api_hash combination is invalid', 
    'PHONE_NUMBER_OCCUPIED' => 'The phone number is already in use', 
    'PHONE_NUMBER_UNOCCUPIED' => 'The phone number is not yet being used', 
    'USERS_TOO_FEW' => 'Not enough users (to create a chat, for example)', 
    'USERS_TOO_MUCH' => 'The maximum number of users has been exceeded (to create a chat, for example)', 
    'TYPE_CONSTRUCTOR_INVALID' => 'The type constructor is invalid', 
    'FILE_PART_INVALID' => 'The file part number is invalid', 
    'FILE_PARTS_INVALID' => 'The number of file parts is invalid', 
    'MD5_CHECKSUM_INVALID' => 'The MD5 checksums do not match', 
    'PHOTO_INVALID_DIMENSIONS' => 'The photo dimensions are invalid', 
    'FIELD_NAME_INVALID' => 'The field with the name FIELD_NAME is invalid', 
    'FIELD_NAME_EMPTY' => 'The field with the name FIELD_NAME is missing', 
    'MSG_WAIT_FAILED' => 'A waiting call returned an error', 
    'USERNAME_NOT_OCCUPIED' => 'The provided username is not occupied', 
    'PHONE_NUMBER_BANNED' => 'The provided phone number is banned from telegram', 
    'AUTH_KEY_UNREGISTERED' => 'The authorization key has expired', 
    'INVITE_HASH_EXPIRED' => 'The invite link has expired', 
    'USER_DEACTIVATED' => 'The user was deactivated', 
    'USER_ALREADY_PARTICIPANT' => 'The user is already in the group', 
    'MESSAGE_ID_INVALID' => 'The provided message id is invalid', 
    'PEER_ID_INVALID' => 'The provided peer id is invalid', 
    'CHAT_ID_INVALID' => 'The provided chat id is invalid', 
    'MESSAGE_DELETE_FORBIDDEN' => "You can't delete one of the messages you tried to delete, most likely because it is a service message.", 
    'CHAT_ADMIN_REQUIRED' => 'You must be an admin in this chat to do this', 
    -429 => 'Too many requests', 
    'PEER_FLOOD' => "You are spamreported, you can't do this"
];
*/