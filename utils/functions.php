<?php

declare(strict_types=1);

use danog\madelineproto\API;
use danog\madelineproto\Logger;
use danog\MadelineProto\RPCErrorException;
use danog\madelineproto\MTProto;
use function Amp\File\{get, put, exists, getSize};

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

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_begins_with')) {
    function str_begins_with(string $haystack, string $needle): bool
    {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
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

function getWebServerName(): ?string
{
    return $_SERVER['SERVER_NAME'] ?? null;
}
function setWebServerName(string $serverName): void
{
    if ($serverName !== '') {
        $_SERVER['SERVER_NAME'] = $serverName;
    }
}

function getUserAgent(): ?string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? null;
}

function logit(string $entry, object $api = null, int $level = \danog\madelineproto\Logger::NOTICE): \Generator
{
    if ($api) {
        return yield $api->logger($entry, $level);
    } else {
        return \danog\madelineproto\Logger::log($entry, $level);
    }
}

function getRequestURL(): ?string
{
    //$_SERVER['REQUEST_URI'] => '/base/?MadelineSelfRestart=1755455420394943907'
    $url = null;
    if (PHP_SAPI !== 'cli') {
        $url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
    return $url;
}

function includeMadeline(string $spec = 'phar'): void
{
    $parts = explode(' ', trim($spec));
    $source = $parts[0];
    $param  = $parts[1] ?? null;
    switch ($source) {
        case 'phar':
            if (!\file_exists('madeline.php')) {
                \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
            }
            $param = $param ?? '5.1.34';
            define('MADELINE_BRANCH', $param);
            include 'madeline.php';
            break;
        case 'composer':
            $prefix = !$param ? '' : ($param . '/');
            include $prefix . 'vendor/autoload.php';
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

    $vars['execute']   = $eh->canExecute();

    $vars['fromRobot'] = $update['message']['out'] ?? false;
    $vars['toRobot']   = $vars['peerType'] === 'peerUser'    && $vars['peer']['user_id']    === $eh->getRobotId();
    $vars['fromAdmin'] = in_array($vars['fromId'], $eh->getAdminIds()) || $vars['fromRobot'];
    $vars['toOffice']  = $vars['peerType'] === 'peerChannel' && $vars['peer']['channel_id'] === $eh->getOfficeId();

    $vars['isCommand'] = ($update['_'] === 'updateNewMessage') && $vars['msgText'] &&
        (strpos($eh->getPrefixes(), $vars['msgText'][0]) !== false) && $vars['execute'] &&
        ($vars['fromRobot'] && $vars['toRobot'] || $vars['fromAdmin'] && $vars['toOffice']);

    if ($vars['isCommand']) {
        $vars['command'] = \parseCommand($update, $eh->getPrefixes());
        $vars['verb']    = $vars['command']['verb'];
    } else {
        $vars['verb']    = '';
    }

    return $vars;
}

function makeDataDirectory($directory): string
{
    if (file_exists($directory)) {
        if (!is_dir($directory)) {
            throw new \ErrorException('data folder already exists as a file');
        }
    } else {
        mkdir($directory);
    }
    $dataDirectory = realpath($directory);
    return $dataDirectory;
}

function makeDataFile($dataDirectory, $dataFile): string
{
    $fullPath = $dataDirectory . '/' . $dataFile;
    if (!file_exists($fullPath)) {
        \touch($fullPath);
    }
    $real = realpath('data/' . $dataFile);
    return $fullPath;
}

function makeWebServerName(): ?string
{
    $webServerName = null;
    if (PHP_SAPI !== 'cli') {
        $webServerName = getWebServerName();
        if (!$webServerName) {
            echo ("To enable the restart, the constant SERVER_NAME must be defined!" . PHP_EOL);
            $webServerName = '';
        }
    }
    return $webServerName;
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
    $mp->logger($json, \danog\MadelineProto\Logger::ERROR);
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
        $mp->logger($countMsg, Logger::ERROR);
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
            $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if (!isset($res['count'])) {
            $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if ($pauseMin > 0 || $pauseMax > 0) {
            $pause = $pauseMax <= $pauseMin ? $pauseMin : rand($pauseMin, $pauseMax);
            yield $mp->sleep($pause);
        } else {
            //$mp->logger(" ", Logger::ERROR);
        }
    } // end of while/for
}

function authorizationState(object $api): int
{
    if (isset($api) && !isset($api->API)) {
        return -2;
    }
    return $api ? ($api->API ? $api->API->authorized : 4) : 5;
}
function authorizationStateDesc(int $authorized): string
{
    switch ($authorized) {
        case  0:
            return 'NOT_LOGGED_IN';
        case  1:
            return 'WAITING_CODE';
        case  2:
            return 'WAITING_PASSWORD';
        case  3:
            return 'LOGGED_IN';
        case 4:
            return 'INVALID_APP';
        case 5:
            return 'NULL_API_OBJECT';
        case -1:
            return 'WAITING_SIGNUP';
        case -2:
            return 'UNINSTANTIATED_MTPROTO';
        default:
            throw new \ErrorException("Invalid authorization status: $authorized");
    }
}


function problematicAccount(object $api): bool
{
    return authorizationState($api) === MTProto::LOGGED_IN && !$api->hassAllAuth();
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
        $strval = number_format($load[0], 2, '.', '');
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

/*
Interface, LaunchMethod
1) Web, Manual
2) Web, Cron
3) Web, Restart
4) CLI, Manual
5) CLI, Cron
*/
//if (isset($_REQUEST['MadelineSelfRestart'])) {
//    Logger::log("Self-restarted, restart token " . $_REQUEST['MadelineSelfRestart'], Logger::ERROR);
//}
function getLaunchMethod(): string
{
    if (PHP_SAPI === 'cli') {
        $interface = 'cli';
        if (PHP_OS_FAMILY === "Linux") {
            if ($_SERVER['TERM']) {
                $launchMethod = 'manual';
            } else {
                $launchMethod = 'cron';
            }
        } elseif (PHP_OS_FAMILY === "Windows") {
            $launchMethod = 'manual';
        } else {
            throw new Exception('Unknown OS!');
        }
    } else {
        $interface    = 'web';
        $launchMethod = 'UNKNOWN';
        if (isset($_REQUEST['MadelineSelfRestart'])) {
            $launchMethod = 'restart';
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $requestUri = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
            if (stripos($requestUri, '?MadelineSelfRestart=') !== false) {
                $launchMethod = 'restart';
            } else if (stripos($requestUri, 'cron') !== false) {
                $launchMethod = 'cron';
            } else {
                $launchMethod = 'manual';
            }
        }
    }
    return $launchMethod;
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

function checkTooManyRestartsAsync(object $eh, string $startupFilename): \Generator
{
    //$startupFilename = 'data/startups.txt';
    $startups = [];
    if (yield exists($startupFilename)) {
        $startupsText = yield get($startupFilename);
        $startups = explode('\n', $startupsText);
    } else {
        // Create the file
    }
    $startupsCount0 = count($startups);

    $nowMilli = intval(microtime(true) * 1000);
    $aMinuteAgo = $nowMilli - 60 * 1000;
    foreach ($startups as $index => $startupstr) {
        $startup = intval($startupstr);
        if ($startup < $aMinuteAgo) {
            unset($startups[$index]);
        }
    }
    $startups[] = strval($nowMilli);
    $startupsText = implode('\n', $startups);
    yield put($startupFilename, $startupsText);
    $restartsCount = count($startups);
    yield $eh->logger("startups: {now:$nowMilli, count0:$startupsCount0, count1:$restartsCount}", Logger::ERROR);
    return $restartsCount;
}

function checkTooManyRestarts(string $startupFilename): int
{
    $startups = [];
    if (\file_exists($startupFilename)) {
        $startupsText = \file_get_contents($startupFilename);
        $startups = explode("\n", $startupsText);
    } else {
        // Create the file
    }

    $nowMilli = intval(microtime(true) * 1000);
    $aMinuteAgo = $nowMilli - 60 * 1000;
    foreach ($startups as $index => $startupstr) {
        $startup = intval($startupstr);
        if ($startup < $aMinuteAgo) {
            unset($startups[$index]);
        }
    }
    $startups[] = strval($nowMilli);
    $startupsText = implode("\n", $startups);
    \file_put_contents($startupFilename, $startupsText);
    $restartsCount = count($startups);
    return $restartsCount;
}

function myStartAndLoop(API $MadelineProto, string $eventHandler, array $genLoops = [], int $maxRecycles = 10): void
{
    $maxRecycles  = 10;
    $recycleTimes = [];
    while (true) {
        try {
            $MadelineProto->loop(function () use ($MadelineProto, $eventHandler, $genLoops) {
                yield $MadelineProto->start();
                yield $MadelineProto->setEventHandler($eventHandler);
                foreach ($genLoops as $genLoop) {
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

function safeStartAndLoop(API $mp, string $eventHandler, ?string &$stopReason, bool $newSession): void
{
    $mp->async(true);
    //$mp->__set('config', $robotConfig);
    $mp->loop(function () use ($mp, $eventHandler, &$stopReason, $newSession) {
        $errors = [];
        while (true) {
            try {
                $started = false;

                $authStateBefore = authorizationState($mp);
                $stateBeforeStr  = authorizationStateDesc($authStateBefore);
                $hasAllAuth      = $authStateBefore === -2 ? false : $mp->hasAllAuth();  // -2 => 'UNINSTANTIATED_MTPROTO' => 'isset($api) && !isset($api->API)'
                $hasAllAuthStr   = "Has all authorizations: " . ($hasAllAuth ? "'true'" : "'false'");
                $mp->logger("Authorization state before invoking the start method: '$stateBeforeStr'!  " . $hasAllAuthStr, Logger::ERROR);
                if (!$mp->hasAllAuth() || authorizationState($mp) !== MTProto::LOGGED_IN) {
                    $mp->logger("Not Logged-in!", Logger::ERROR);
                }
                if ($authStateBefore === 4) {  // 4 => 'INVALID_APP' 
                    echo (PHP_EOL . "Invalid App, or the Session is corrupted!<br>" . PHP_EOL . PHP_EOL);
                    Logger::log("Invalid App, or the Session is corrupted!", Logger::ERROR);
                }
                if ($authStateBefore === MTProto::LOGGED_IN && !$hasAllAuth) {
                    Logger::log("The Session is terminated or corrupted!", Logger::ERROR);
                }

                $stopReasonSaved = $stopReason;
                $stopReason = 'authorization';
                $me = yield $mp->start();
                $stopReason = $stopReasonSaved;

                $stateAfter    = authorizationState($mp);
                $stateAfterStr = authorizationStateDesc($stateAfter);
                $hasAllAuth = "Has all authorizations: " . ($mp->hasAllAuth() ? "'true'" : "'false'");
                $mp->logger("Authorization state after invoking the start method is '$stateAfterStr'!" . $hasAllAuth, Logger::ERROR);
                if (!$mp->hasAllAuth() || authorizationState($mp) !== 3) {
                    $mp->logger("Unsuccessful Login!", Logger::ERROR);
                    throw new ErrorException('Unsuccessful Login!');
                } else {
                    if ($newSession) {
                        $new = saveNewSessionCreation(DATA_DIRECTORY, 'creation.txt', SCRIPT_START_TIME);
                        if (!$new) {
                            throw new ErrorException('Session creation logic error');
                        }
                    }
                    $mp->logger("Robot is currently logged-in!", Logger::ERROR);
                }
                if (!$me || !is_array($me)) {
                    throw new ErrorException('Invalid Self object');
                }
                \closeConnection('Bot was started!');

                if (!$mp->hasEventHandler()) {
                    yield $mp->setEventHandler($eventHandler);
                    yield $mp->logger("EventHandler is set!", Logger::ERROR);
                } else {
                    yield $mp->setEventHandler($eventHandler); // For now.  To be investigated
                    yield $mp->logger("EventHandler was already set!", Logger::ERROR);
                }

                if (\method_exists($eventHandler, 'finalizeStart')) {
                    $eh = $mp->getEventHandler($eventHandler);
                    yield $eh->finalizeStart($mp);
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

function simpleStartAndLoop(API $mp, string $eventHandler): void
{
    $mp->async(true);
    $mp->loop(function () use ($mp, $eventHandler) {
        yield $mp->start();
        \error_clear_last();
        $mp->setEventHandler($eventHandler);
        \error_clear_last();
    });
    $mp->loop();
}

function secondsToNexMinute(float $now = null): int
{
    $now = $now ?? \microtime(true);
    $now  = (int) ($now * 1000000);
    $next = (int)ceil($now / 60000000) * 60000000;
    $diff = ($next - $now);
    $secs = (int)round($diff / 1000000);
    //echo ("{now: $now, next: $next, diff: $diff, secs: $secs}" . PHP_EOL);
    return $secs > 0 ? $secs : 60;
}

function madelineMajorVersion(): int
{
    return MTProto::V > 137 ? 6 : (MTProto::V > 105 ? 5 : 4);
}

function logCallStack(int $level = Logger::NOTICE): void
{
    $e = new \Exception;
    Logger::log($e->getTraceAsString(), $level);
}
