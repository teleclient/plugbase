<?php

declare(strict_types=1);

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

        $datetime = \DateTime::createFromFormat('U.u', number_format($microtime, 6, '.', ''));
        $datetime->setTimeZone($this->timeZoneObj);
        return $datetime->format($format);

        $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($microtime, 6, '.', ''));
        $dateObj = $dateObj->setTimeZone($this->timeZoneObj);
        return $dateObj->format($format);
    }

    function mySqlmicro(float $time = null, $format = 'Y-m-d H:i:s.u'): string
    {
        $time = $time ?? \microtime(true);

        $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
        $dateObj = $dateObj->setTimeZone($this->timeZoneObj);
        return $dateObj->format($format);
    }

    public static function duration(float $startTime, float $endTime = null): string
    {
        $endTime = $endTime ?? microtime(true);
        if ($endTime < 10) {
            return 'UNAVAILABLE';
        }

        $diff = $endTime - $startTime;

        $sec   = intval($diff);
        $micro = $diff - $sec;
        //\danog\MadelineProto\Logger::log("Parts to Debug: $sec  $micro  Diff: $diff  Start: $startTime End: $endTime", \danog\MadelineProto\Logger::ERROR);
        return \strftime('%T', mktime(0, 0, $sec)) . str_replace('0.', '.', sprintf('%.3f', $micro));
    }

    public static function toMilli(float $microtime = null): int
    {
        $microtime = $microtime ?? microtime(true);
        return intval($microtime * 1000);

        //$mt = explode(' ', microtime());
        //return ((int)$mt[1]) * 1000 + ((int)round($mt[0] * 1000));
    }
}


function milliDate_DELETE(string $zone, float $time = null, string $format = 'H:i:s.v'): string
{
    $time   = $time ?? \microtime(true);
    $zoneObj = new \DateTimeZone($zone);
    $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
    $dateObj->setTimeZone($zoneObj);
    return $dateObj->format($format);
}

function computeDuration_DELETE(float $startTime, float $endTime = null): string
{
    $endTime = $endTime ?? microtime(true);

    $diff  = $endTime - $startTime;

    $sec   = intval($diff);
    $micro = $diff - $sec;
    //\danog\MadelineProto\Logger::log("Parts to Debug: $sec  $micro  Diff: $diff  Start: $startTime End: $endTime", \danog\MadelineProto\Logger::ERROR);
    return \strftime('%T', mktime(0, 0, $sec)) . str_replace('0.', '.', sprintf('%.3f', $micro));

    /*
    $end = $end ?? \microtime(true);
    $age     = intval($end - $start); // seconds
    $days    = floor($age  / 86400);
    $hours   = floor(($age / 3600) % 3600);
    $minutes = floor(($age / 60) % 60);
    $seconds = $age % 60;
    $ageStr  = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
    return $ageStr;
    */
}

function timeDiffFormatted_DELETE(float $startTime, float $endTime = null): string
{
    $endTime = $endTime ?? microtime(true);

    $diff = $endTime - $startTime;

    $sec   = intval($diff);
    $micro = $diff - $sec;
    //\danog\MadelineProto\Logger::log("Parts to Debug: $sec  $micro  Diff: $diff  Start: $startTime End: $endTime", \danog\MadelineProto\Logger::ERROR);
    return \strftime('%T', mktime(0, 0, $sec)) . str_replace('0.', '.', sprintf('%.3f', $micro));
}

function nowMilli_DELETE(): int
{
    $mt = explode(' ', microtime());
    return ((int)$mt[1]) * 1000 + ((int)round($mt[0] * 1000));
}
