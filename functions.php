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
