<?php

declare(strict_types=1);

class BuiltinPlugin
{
    private EventHandler $api;

    function __construct(EventHandler $api)
    {
        $this->api = $api;
    }

    public function onStart(): \Generator
    {
        return;
        yield;
    }

    public function __invoke(array $update): \Generator
    {

        if (false && isset($update['message']['media']) && $update['message']['media']['_'] !== 'messageMediaGame') {
            yield $this->downloadToDir($update, '/tmp');
            yield $this->messages->sendMedia([
                'peer' => $update,
                'message' => $update['message']['message'],
                'media' => $update
            ]);
        }

        $res = json_encode($update, JSON_PRETTY_PRINT);

        yield $this->sleep(3);

        try {
            yield $this->sm($update, "<code>$res</code>\nAsynchronously, after 3 seconds");
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            \danog\MadelineProto\Logger::log((string) $e, \danog\MadelineProto\Logger::FATAL_ERROR);
        } catch (\danog\MadelineProto\Exception $e) {
            \danog\MadelineProto\Logger::log((string) $e, \danog\MadelineProto\Logger::FATAL_ERROR);
        }
    }
}
