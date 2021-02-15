<?php

declare(strict_types=1);

use danog\madelineproto\Logger;
use danog\MadelineProto\RPCErrorException;

function toJSON($var, bool $pretty = true): ?string
{
    if (isset($var['request'])) {
        unset($var['request']);
    }
    $opts = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | ($pretty ? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '') ? $json : var_export($var, true);
    return ($json != false) ? $json : null;
}

function parseCommand(array $update, string $prefixes = '!/', int $maxParams = 3): array
{
    $command = ['prefix' => '', 'verb' => null, 'params' => []];
    if ($update['_'] === 'updateNewMessage' && isset($update['message']['message'])) {
        $msg = $update['message']['message'];
        if (strlen($msg) >= 2 && strpos($prefixes, $msg[0]) !== false) {
            $verb = strtolower(substr($msg, 1, strpos($msg . ' ', ' ') - 1));
            if (ctype_alnum($verb)) {
                $command['prefix'] = $msg[0];
                $command['verb']   = $verb;
                $tokens = explode(' ', $msg, $maxParams + 1);
                for ($i = 1; $i < count($tokens); $i++) {
                    $command['params'][$i - 1] = trim($tokens[$i]);
                }
            }
        }
    }
    return $command;
}

function logit(string $entry, object $api = null, int $level = \danog\madelineproto\Logger::NOTICE): \Generator
{
    if ($api) {
        return yield $api->logger($entry, $level);
    } else {
        return \danog\madelineproto\Logger::log($entry, $level);
    }
}

function includeMadeline(string $source = 'phar', string $param = '')
{
    switch ($source) {
        case 'phar':
            if (!\file_exists('madeline.php')) {
                \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
            }
            include 'madeline.php';
            break;
        case 'composer':
            include 'vendor/autoload.php';
            break;
        default:
            throw new \ErrorException("Invalid argument: '$source'");
    }
}

function computeVars(array $update, object $eh): array
{
    $vars['msgType']   = $update['_'];
    $vars['msgDate']   = $update['message']['date'] ?? null;
    $vars['msgId']     = $update['message']['id'] ?? null;
    $vars['msgText']   = $update['message']['message'] ?? null;
    $vars['fromId']    = $update['message']['from_id'] ?? null;
    $vars['replyToId'] = $update['message']['reply_to_msg_id'] ?? null;
    $vars['peerType']  = $update['message']['to_id']['_'] ?? null;
    $vars['peer']      = $update['message']['to_id'] ?? null;
    $vars['isOutward'] = $update['message']['out'] ?? false;

    $vars['config']    = $eh->getRobotConfig();
    $vars['robotId']   = $eh->getRobotId();
    $vars['adminIds']  = $eh->getAdminIds();
    $vars['officeId']  = $eh->getOfficeId();
    $vars['execute']   = $eh->canExecute();
    $vars['prefixes']  = $eh->getPrefixes();

    $vars['fromRobot'] = $update['message']['out'] ?? false;
    $vars['toRobot']   = $vars['peerType'] === 'peerUser'    && $vars['peer']['user_id']    === $vars['robotId'];
    $vars['fromAdmin'] = in_array($vars['fromId'], $vars['adminIds']) || ['fromRobot'];
    $vars['toOffice']  = $vars['peerType'] === 'peerChannel' && $vars['peer']['channel_id'] === $vars['officeId'];

    $vars['isCommand'] = ($update['_'] === 'updateNewMessage') && $vars['msgText'] &&
        (strpos($vars['prefixes'], $vars['msgText'][0]) !== false) && $vars['execute'] &&
        ($vars['fromRobot'] && $vars['toRobot'] || $vars['fromAdmin'] && $vars['toOffice']);

    if ($vars['isCommand']) {
        $vars['command']  = \parseCommand($update, $vars['config']['prefixes'] ?? '/!');
        $vars['verb']     = $vars['command']['verb'];
    } else {
        $vars['verb']     = '';
    }

    return $vars;
}

function milliDate(string $zone, float $time = null, string $format = 'H:i:s.v'): string
{
    $time   = $time ?? \microtime(true);
    $zoneObj = new \DateTimeZone($zone);
    $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
    $dateObj->setTimeZone($zoneObj);
    return $dateObj->format($format);
}

class UserDate
{
    private \DateTimeZone $timeZoneObj;

    function __construct(string $zone)
    {
        $this->timeZoneObj = new \DateTimeZone($zone);
    }

    public function getZone(): string
    {
        return $this->timeZoneObj->getName();
    }

    public function format(float $microtime = null, string $format = 'H:i:s.v'): string
    {
        $microtime = $microtime ?? \microtime(true);

        $datetime = DateTime::createFromFormat('U.u', number_format($microtime, 6, '.', ''));
        $datetime->setTimeZone($this->timeZoneObj);
        return $datetime->format($format);

        $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($microtime, 6, '.', ''));
        $dateObj = $dateObj->setTimeZone($this->timeZoneObj);
        return $dateObj->format($format);
    }

    function mySqlmicro(float $time = null, $format = 'Y-m-d H:i:s.u'): string
    {
        $time  = $time ?? \microtime(true);

        $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
        $dateObj = $dateObj->setTimeZone($this->timeZoneObj);
        return $dateObj->format($format);
    }

    function duration(float $start, float $end = null): string
    {
        $end = $end ?? \microtime(true);
        $diff = $end - $start;

        // Break the difference into seconds and microseconds
        $secs = intval($diff);
        $micro = $diff - $secs;

        // $final will contain something like "00:00:02.452"
        //$final = strftime('%T', mktime(0, 0, $sec)) . str_replace('0.', '.', sprintf('%.3f', $micro));
        //return $final;

        $days    = floor($secs  / 86400);
        $hours   = floor(($secs / 3600) % 3600);
        $minutes = floor(($secs / 60) % 60);
        $seconds = $secs % 60;
        $ageStr  = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds) . str_replace('0.', '.', sprintf('%.3f', $micro));
        return $ageStr;
    }
}

function resolveDialog($mp, array $dialog, array $messages, array $chats, array $users)
{
    $peer     = $dialog['peer'];
    $message  =  null;
    foreach ($messages as $msg) {
        if ($dialog['top_message'] === $msg['id']) {
            $message = $msg;
            break;
        }
    }
    if ($message === null) {
        throw new Exception("Missing top-message: " . toJSON($dialog));
    }
    $peerId  = null;
    $subtype = null;
    $name    = null;
    $peerval = null;
    switch ($peer['_']) {
        case 'peerUser':
            $peerId = $peer['user_id'];
            foreach ($users as $user) {
                if ($peerId === $user['id']) {
                    $subtype = ($user['bot'] ?? false) ? 'bot' : 'user';
                    $peerval = $user;
                    if (isset($user['username'])) {
                        $name = '@' . $user['username'];
                    } elseif (($user['first_name'] ?? '') !== '' || ($user['last_name'] ?? '') !== '') {
                        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    } elseif (isset($user['id'])) {
                        $name = strval($user['id']);
                    } else {
                        $name = '';
                    }
                    if (!isset($message['from_id'])) {
                        $mp->logger('ERROR user: '    . toJSON($user),    Logger::ERROR);
                        $mp->logger('ERROR message: ' . toJSON($message), Logger::ERROR);
                        throw new Exception('Mismatch');
                    }
                    break 2;
                }
            }
            throw new Exception("Missing user: '$peerId'");
        case 'peerChat':
        case 'peerChannel':
            $peerId = $peer['_'] === 'peerChat' ? $peer['chat_id'] : $peer['channel_id'];
            foreach ($chats as $chat) {
                if ($chat['id'] === $peerId) {
                    $peerval = $chat;
                    if (isset($chat['username'])) {
                        $name = $chat['username'];
                    } elseif (($chat['title'] ?? '') !== '') {
                        $name = $chat['title'];
                    } elseif (isset($chat['id'])) {
                        $name = strval($chat['id']);
                    } else {
                        $name = '';
                    }
                    switch ($chat['_']) {
                        case 'chatEmpty':
                            $subtype = $chat['_'];
                            break;
                        case 'chat':
                            $subtype = 'basicgroup';
                            break;
                        case 'chatForbidden':
                            $subtype = $chat['_'];
                            break;
                        case 'channel':
                            $subtype = ($chat['megagroup'] ?? false) ? 'supergroup' : 'channel';
                            break;
                        case 'channelForbidden':
                            $subtype = $chat['_'];
                            break;
                        default:
                            throw new Exception("Unknown subtype: '$peerId'  '" . $chat['_'] . "'");
                    }
                    break 2;
                }
            }
            throw new Exception("Missing chat: '$peerId'");
        default:
            throw new Exception("Invalid peer type: '" . $peer['_'] . "'");
    }
    return [
        'botapi_id'    => $peerId,
        'subtype'      => $subtype,
        'name'         => $name,
        'dialog'       => $dialog,
        'user_or_chat' => $peerval,
        'message'      => $message
    ];
}

function visitAllDialogs(object $mp, ?array $params, Closure $sliceCallback = null): \Generator
{
    foreach ($params as $key => $param) {
        switch ($key) {
            case 'limit':
            case 'max_dialogs':
            case 'pause_min':
            case 'pause_max':
                break;
            default:
                throw new Exception("Unknown Parameter: $key");
        }
    }
    $limit      = $params['limit']       ?? 100;
    $maxDialogs = $params['max_dialogs'] ?? 100000;
    $pauseMin   = $params['pause_min']   ?? 0;
    $pauseMax   = $params['pause_max']   ?? 0;
    $pauseMax   = $pauseMax < $pauseMin ? $pauseMin : $pauseMax;
    $json = toJSON([
        'limit'       => $limit,
        'max_dialogs' => $maxDialogs,
        'pause_min'   => $pauseMin,
        'pause_max'   => $pauseMax
    ]);
    yield $mp->logger($json, \danog\MadelineProto\Logger::ERROR);
    $limit = min($limit, $maxDialogs);
    $params = [
        'offset_date' => 0,
        'offset_id'   => 0,
        'offset_peer' => ['_' => 'inputPeerEmpty'],
        'limit'       => $limit,
        'hash'        => 0,
    ];
    $res = ['count' => 1];
    $fetched     = 0;
    $dialogIndex = 0;
    $sentDialogs = 0;
    $dialogIds   = [];
    while ($fetched < $res['count']) {
        //yield $mp->logger('Request: ' . toJSON($params, false), Logger::ERROR);
        try {
            //==============================================
            $res = yield $mp->messages->getDialogs($params, ['FloodWaitLimit' => 200]);
            //==============================================
        } catch (RPCErrorException $e) {
            if (\strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                throw new Exception('FLOOD' . $e->rpc);
            }
        }

        $sliceSize    = count($res['dialogs']);
        $totalDialogs = isset($res['count']) ? $res['count'] : $sliceSize;

        $messageCount = count($res['messages']);
        $chatCount    = count($res['chats']);
        $userCount    = count($res['users']);
        $fetchedSofar = $fetched + $sliceSize;
        $countMsg     = "Result: {dialogs:$sliceSize, messages:$messageCount, chats:$chatCount, users:$userCount " .
            "total:$totalDialogs fetched:$fetchedSofar}";
        yield $mp->logger($countMsg, Logger::ERROR);
        if (count($res['messages']) !== $sliceSize) {
            throw new Exception('Unequal slice size.');
        }

        if ($sliceCallback !== null) {
            //===================================================================================================
            foreach ($res['dialogs'] ?? [] as $dialog) {
                $dialogInfo = yield resolveDialog($mp, $dialog, $res['messages'], $res['chats'], $res['users']);
                $botapiId = $dialogInfo['botapi_id'];
                if (!isset($dialogIds[$botapiId])) {
                    $dialogIds[] = $botapiId;
                    yield $sliceCallback(
                        $mp,
                        $totalDialogs,
                        $dialogIndex,
                        $dialogInfo['botapi_id'],
                        $dialogInfo['subtype'],
                        $dialogInfo['name'],
                        $dialogInfo['dialog'],
                        $dialogInfo['user_or_chat'],
                        $dialogInfo['message']
                    );
                    $dialogIndex += 1;
                    $sentDialogs += 1;
                }
            }
            //===================================================================================================
            //yield $mp->logger("Sent Dialogs:$sentDialogs,  Max Dialogs:$maxDialogs, Slice Size:$sliceSize", Logger::ERROR);
            if ($sentDialogs >= $maxDialogs) {
                break;
            }
        }

        $lastPeer = 0;
        $lastDate = 0;
        $lastId   = 0;
        $res['messages'] = \array_reverse($res['messages'] ?? []);
        foreach (\array_reverse($res['dialogs'] ?? []) as $dialog) {
            $fetched += 1;
            $id = yield $mp->getId($dialog['peer']);
            if (!$lastDate) {
                if (!$lastPeer) {
                    $lastPeer = $id;
                    //yield $mp->logger("lastPeer is set to $id.", Logger::ERROR);
                }
                if (!$lastId) {
                    $lastId = $dialog['top_message'];
                    //yield $mp->logger("lastId is set to $lastId.", Logger::ERROR);
                }
                foreach ($res['messages'] as $message) {
                    $idBot = yield $mp->getId($message);
                    if (
                        $message['_'] !== 'messageEmpty' &&
                        $idBot  === $lastPeer            &&
                        $lastId === $message['id']
                    ) {
                        $lastDate = $message['date'];
                        //yield $mp->logger("lastDate is set to $lastDate from {$message['id']}.", Logger::ERROR);
                        break;
                    }
                }
            }
        }
        if ($lastDate) {
            $params['offset_date'] = $lastDate;
            $params['offset_peer'] = $lastPeer;
            $params['offset_id']   = $lastId;
            $params['count']       = $sliceSize;
        } else {
            yield $mp->echo('*** NO LAST-DATE EXISTED' . PHP_EOL);
            yield $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if (!isset($res['count'])) {
            yield $mp->echo('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...' . PHP_EOL);
            yield $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if ($pauseMin > 0 || $pauseMax > 0) {
            $pause = $pauseMax <= $pauseMin ? $pauseMin : rand($pauseMin, $pauseMax);
            //yield $mp->logger("Pausing for $pause seconds. ...", Logger::ERROR);
            //yield $mp->logger(" ", Logger::ERROR);
            yield $mp->sleep($pause);
        } else {
            //yield $mp->logger(" ", Logger::ERROR);
        }
    } // end of while/for
}

function authorizationState(object $api): int
{
    return $api ? ($api->API ? $api->API->authorized : 4) : 5;
}
function authorizationStateDesc(int $authorized): string
{
    switch ($authorized) {
        case  3:
            return 'LOGGED_IN';
        case  0:
            return 'NOT_LOGGED_IN';
        case  1:
            return 'WAITING_CODE';
        case  2:
            return 'WAITING_PASSWORD';
        case -1:
            return 'WAITING_SIGNUP';
        case 4:
            return 'INVALID_APP';
        case 5:
            return 'NULL_API_OBJECT';
        default:
            throw new \ErrorException("Invalid authorization status: $authorized");
    }
}

function respond(object $eh, array $peer, int $msgId, string $text, bool $edit = null): \Generator
{
    $edit = $edit ?? $eh->getEditMessage();

    if ($edit) {
        $result = yield $eh->messages->editMessage([
            'peer'       => $peer,
            'id'         => $msgId,
            'message'    => $text,
            'parse_mode' => 'HTML',
        ]);
    } else {
        $result = yield $eh->messages->sendMessage([
            'peer'            => $peer,
            'reply_to_msg_id' => $msgId,
            'message'         => $text,
            'parse_mode'      => 'HTML',
        ]);
    }
    return $result;
}

function getFileSize(string $file): int
{
    clearstatcache(true, $file);
    $size = filesize($file);
    return $size !== false ? $size : 0;

    if ($size === false) {
        $sessionSize = '_UNAVAILABLE_';
    } elseif ($size < 1024) {
        $sessionSize = $size . ' B';
    } elseif ($size < 1048576) {
        $sessionSize = round($size / 1024, 0) . ' KB';
    } else {
        $sessionSize = round($size / 1048576, 0) . ' MB';
    }
    return $sessionSize;
}

function computeDuration(float $start, float $end = null): string
{
    $end = $end ?? \microtime(true);
    $age     = intval($end - $start); // seconds
    $days    = floor($age  / 86400);
    $hours   = floor(($age / 3600) % 3600);
    $minutes = floor(($age / 60) % 60);
    $seconds = $age % 60;
    $ageStr  = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
    return $ageStr;
}

function hostName(bool $full = false): string
{
    $name = \getHostname();
    if (!$full && $name && strpos($name, '.') !== false) {
        $name = substr($name, 0, strpos($name, '.'));
    }
    return $name;
}

function getCpuUsage(): string
{
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $strval = number_format($load[0], 2, '.', '') . '%';
        return $strval;
    } else {
        return 'UNAVAILABLE';
    }
}

function getWinMemory(): int
{
    $cmd = 'tasklist /fi "pid eq ' . strval(getmypid()) . '"';
    $tasklist = trim(exec($cmd, $output));
    $mem_val = mb_strrchr($tasklist, ' ', TRUE);
    $mem_val = trim(mb_strrchr($mem_val, ' ', FALSE));
    $mem_val = str_replace('.', '', $mem_val);
    $mem_val = str_replace(',', '', $mem_val);
    $mem_val = intval($mem_val);
    return $mem_val;
}

function getPeakMemory(): int
{
    switch (PHP_OS_FAMILY) {
        case 'Linux':
            $mem = memory_get_peak_usage(true);
            break;
        case 'Windows':
            $mem = getWinMemory();
            break;
        default:
            throw new Exception('Unknown OS: ' . PHP_OS_FAMILY);
    }
    return $mem;
}

function getCurrentMemory(): int
{
    switch (PHP_OS_FAMILY) {
        case 'Linux':
            $mem = memory_get_usage(true);
            break;
        case 'Windows':
            $mem = memory_get_usage(true);
            break;
        default:
            throw new Exception('Unknown OS: ' . PHP_OS_FAMILY);
    }
    return $mem;
}

/*
function getSizeString(int $size): string
{
    $unit = array('Bytes', 'KB', 'MB', 'GB', 'TB', 'PB');
    $mem  = $size !== 0 ? round($size / pow(1024, ($x = floor(log($size, 1024)))), 2) . ' ' . $unit[$x] : 'UNAVAILABLE';
    return $mem;
}
*/

function formatBytes(int $bytes, int $precision = 2)
{
    $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return number_format($bytes, $precision, '.', '') . ' ' . $units[$pow];
}

function oneOf(array $update, string $tails = 'NewMessage|NewChannelMessage|EditMessage|EditChannelMessage'): bool
{
    return strpos($tails, substr($update['_'], 6)) !== false;
}

function hasText(array $update): bool
{
    return
        isset($update['message']) && isset($update['message']['message']) && strlen($update['message']['message']) > 1 &&
        $update['message']['_'] !== 'messageService' && $update['message']['_'] !== 'messageEmpty';
}

function initPhp(): void
{
    \date_default_timezone_set('UTC');
    \ignore_user_abort(true);
    \set_time_limit(0);
    \error_reporting(E_ALL);                                 // always TRUE
    ini_set('ignore_repeated_errors', '1');                 // always TRUE
    ini_set('display_startup_errors', '1');
    ini_set('display_errors',         '1');                 // FALSE only in production or real server
    ini_set('default_charset',        'UTF-8');
    ini_set('precision',              '18');
    ini_set('log_errors',             '1');                 // Error logging engine
    ini_set('error_log',              'MadelineProto.log'); // Logging file path
}

function myStartAndLoop(\danog\madelineproto\API $MadelineProto, string $eventHandler, \danog\Loop\Generic\GenericLoop $genLoop = null, int $maxRecycles = 10): void
{
    $maxRecycles  = 10;
    $recycleTimes = [];
    while (true) {
        try {
            $MadelineProto->loop(function () use ($MadelineProto, $eventHandler, $genLoop) {
                yield $MadelineProto->start();
                yield $MadelineProto->setEventHandler($eventHandler);
                if ($genLoop !== null) {
                    $genLoop->start(); // Do NOT use yield.
                }

                // Synchronously wait for the update loop to exit normally.
                // The update loop exits either on ->stop or ->restart (which also calls ->stop).
                \danog\madelineproto\Tools::wait(yield from $MadelineProto->API->loop());
                yield $MadelineProto->logger("Update loop exited!");
            });
            sleep(5);
            break;
        } catch (\Throwable $e) {
            try {
                $MadelineProto->logger->logger((string) $e, \danog\madelineproto\Logger::FATAL_ERROR);
                // quit recycling if more than $maxRecycles happened within the last minutes.
                $now = time();
                foreach ($recycleTimes as $index => $restartTime) {
                    if ($restartTime > $now - 1 * 60) {
                        break;
                    }
                    unset($recycleTimes[$index]);
                }
                if (count($recycleTimes) > $maxRecycles) {
                    // quit for good
                    \danog\madelineproto\Shutdown::removeCallback('restarter');
                    \danog\madelineproto\Magic::shutdown(1);
                    break;
                }
                $recycleTimes[] = $now;
                $MadelineProto->report("Surfaced: $e");
            } catch (\Throwable $e) {
            }
        }
    };
}

function safeStartAndLoop(\danog\madelineproto\API $mp, string $eventHandler, array $robotConfig = [], array $genLoops = []): void
{
    $mp->async(true);
    $mp->__set('config', $robotConfig);
    $mp->loop(function () use ($mp, $eventHandler, $robotConfig, $genLoops) {
        $errors = [];
        while (true) {
            try {
                $started = false;
                if (!$mp->hasAllAuth() || authorizationState($mp) !== 3) {
                    yield $mp->logger("Not Logged-in!", Logger::ERROR);
                }
                $me = yield $mp->start();
                if (!$me || !is_array($me)) {
                    throw new ErrorException('Invalid Self object');
                }
                yield $mp->logger("Robot Id: {$me['id']}", Logger::ERROR);
                yield $mp->setEventHandler($eventHandler);
                $eh = $mp->getEventHandler($eventHandler);
                $eh->setSelf($me);
                $eh->setRobotConfig($robotConfig);
                $eh->setUserDate(new \UserDate($robotConfig['zone'] ?? 'UTC'));
                foreach ($genLoops as $genLoop) {
                    $genLoop->start(); // Do NOT use yield.
                }
                $started = true;
                \danog\madelineproto\Tools::wait(yield from $mp->API->loop());
                break;
            } catch (\Throwable $e) {
                $errors = [\time() => $errors[\time()] ?? 0];
                $errors[\time()]++;
                $fatal = \danog\madelineproto\Logger::FATAL_ERROR;
                if ($errors[\time()] > 10 && (!$mp->inited() || !$started)) {
                    yield $mp->logger->logger("More than 10 errors in a second and not inited, exiting!", $fatal);
                    break;
                }
                yield $mp->logger->logger((string) $e, $fatal);
                yield $mp->report("Surfaced: $e");
            }
        }
    });
}
