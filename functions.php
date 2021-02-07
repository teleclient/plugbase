<?php

declare(strict_types=1);


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
    if ($update['_'] !== 'updateNewMessage' || !isset($update['message']['message'])) {
        $msg = $update['message']['message'];
        //$msg = $msg ? trim($msg) : '';
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

function includeMadeline(string $source = null)
{
    if (!$source) {
        if (\file_exists('vendor/autoload.php')) {
            include 'vendor/autoload.php';
        } else {
            if (!\file_exists('madeline.php')) {
                \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
            }
            include 'madeline.php';
        }
    } elseif ($source === 'composer') {
    } elseif ($source === 'phar') {
    }
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


function myStartAndLoop(\danog\madelineproto\API $MadelineProto, \danog\Loop\Generic\GenericLoop $genLoop = null, int $maxRecycles = 10): void
{
    $maxRecycles  = 10;
    $recycleTimes = [];
    while (true) {
        try {
            $MadelineProto->loop(function () use ($MadelineProto, $genLoop) {
                yield $MadelineProto->start();
                yield $MadelineProto->setEventHandler('\EventHandler');
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

function safeStartAndLoop(\danog\madelineproto\API $mp, string $eventHandler, object $config = null, array $genLoops = []): void
{
    $config = $config ?? (object)[];
    $mp->async(true);
    $mp->loop(function () use ($mp, $eventHandler, $config, $genLoops) {
        $errors = [];
        while (true) {
            try {
                $started = false;
                $me = yield $mp->start();
                if (!$mp->hasAllAuth() || authorizationState($mp) !== 3) {
                    echo ("Not Logged-in!" . PHP_EOL);
                    throw new \ErrorException("Not Logged-in!", \danog\madelineproto\Logger::FATAL_ERROR);
                }
                yield $mp->setEventHandler($eventHandler);
                $eh = $mp->getEventHandler($eventHandler);
                $me = yield $mp->getSelf();
                $mp->echo(toJSON($me) . PHP_EOL);
                if (!$me || !is_array($me)) {
                    throw new ErrorException('Invalid EventHandler object');
                }
                $eh->setSelf($me);
                $eh->setRobotConfig($config);
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
