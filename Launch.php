<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use function Amp\File\{get, put, exists, getSize};

class Launch
{
    private function __construct(string $filename)
    {
    }

    public static function appendLaunchRecord(string $fileName, float $scriptStartTime, string $stopReason): array
    {
        $key = self::strMillitime($scriptStartTime);

        $record['time_start']    = intval($key);
        $record['time_end']      = 0;
        $record['launch_method'] = \getLaunchMethod();
        $record['stop_reason']   = $stopReason;
        $record['memory_start']  = \getPeakMemory();
        $record['memory_middle'] = 0;
        $record['memory_end']    = 0;

        $line = "$key {$record['time_end']} {$record['launch_method']} {$record['stop_reason']} {$record['memory_start']} {$record['memory_middle']} {$record['memory_end']}";
        file_put_contents($fileName, "\n" . $line, FILE_APPEND | LOCK_EX);
        //yield \Amp\File\put($fileName, "\n" . $line);

        return $record;
    }

    public static function updateLaunchRecord(string $fileName, float $scriptStartTime): array
    {
        $key = self::strMillitime($scriptStartTime);

        $record = null;
        $new    = null;
        $lines  = file($fileName);
        //$lines  = yield Amp\File\get($fileName);
        $content = '';
        foreach ($lines as $line) {
            if (strStartsWith($line, $key . ' ')) {
                $items = explode(' ', $line);
                $record['time_start']    = intval($items[0]);
                $record['time_end']      = intval($items[1]);
                $record['launch_method'] = $items[2];
                $record['stop_reason']   = $items[3];
                $record['memory_start']  = intval($items[4]);
                $record['memory_middle'] = \getPeakMemory();
                $record['memory_end']    = intval($items[6]);
                $new = "{$record['time_start']} {$record['time_end']} {$record['launch_method']} {$record['stop_reason']} {$record['memory_start']} {$record['memory_middle']} {$record['memory_end']}";
                $content .= $new . "\n";
            } else {
                $content .= $line;
            }
        }
        if ($new === null) {
            throw new \ErrorException("Launch record not found! key: $scriptStartTime");
        }
        file_put_contents($fileName, rtrim($content));
        //yield Amp\File\put($fileName, rtrim($content));
        return $record;
    }

    public static function finalizeLaunchRecord(string $fileName, float $scriptStartTime, float $scriptEndTime, string $stopReason): array
    {
        $key = self::strMillitime($scriptStartTime);

        $record = null;
        $new    = null;
        $lines  = file($fileName);
        //$lines  = yield Amp\File\get($fileName);
        $content = '';
        foreach ($lines as $line) {
            if (strStartsWith($line, $key . ' ')) {
                $items = explode(' ', $line);
                $record['time_start']    = intval($items[0]);
                $record['time_end']      = intval(round($scriptEndTime * 1000));
                $record['launch_method'] = $items[2]; // \getLaunchMethod();
                $record['stop_reason']   = $stopReason;
                $record['memory_start']  = intval($items[4]);
                $record['memory_middle'] = intval($items[5]);
                $record['memory_end']    = \getPeakMemory();
                $new = "{$record['time_start']} {$record['time_end']} {$record['launch_method']} {$record['stop_reason']} {$record['memory_start']} {$record['memory_middle']} {$record['memory_end']}";
                $content .= $new . "\n";
            } else {
                $content .= $line;
            }
        }
        if ($new === null) {
            throw new \ErrorException("Launch record not found! key: $scriptStartTime");
        }
        file_put_contents($fileName, rtrim($content));
        //yield Amp\File\put($fileName, rtrim($content));
        return $record;
    }

    public static function getPreviousLaunch(object $eh, string $fileName, float $scriptStartTime): \Generator
    {
        $key = self::strMillitime($scriptStartTime);

        $content = yield get($fileName);
        if ($content === '') {
            return null;
        }
        $content = substr($content, 1);
        $lines = explode("\n", $content);
        yield $eh->logger("Launches Count:" . count($lines), Logger::ERROR);
        $record = null;
        foreach ($lines as $line) {
            if (strStartsWith($line, $key . ' ')) {
                break;
            }
            $record = $line;
        }
        if ($record === null) {
            return null;
        }
        $fields = explode(' ', trim($record));
        if (count($fields) !== 6) {
            throw new \ErrorException("Invalid launch information .");
        }
        $launch['time_start']    = intval($fields[0]);
        $launch['time_end']      = intval($fields[1]);
        $launch['launch_method'] = $fields[2];
        $launch['stop_reason']   = $fields[3];
        $launch['memory_start']  = intval($fields[4]);
        $launch['memory_middle'] = intval($fields[5]);
        $launch['memory_end']    = intval($fields[6]);
        return $launch;
    }

    private static function strMillitime(float $microtime): string
    {
        return strval(intval(round($microtime * 1000)));
    }
}
