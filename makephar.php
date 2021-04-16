<?php

declare(strict_types=1);

$srcRoot   = "";
$buildRoot = "build/";
$pharFile  = 'plugbase.phar';

// clean up
if (file_exists($srcRoot . $pharFile)) {
    unlink($srcRoot . $pharFile);
}
if (file_exists($srcRoot . $pharFile . '.gz')) {
    unlink($srcRoot . $pharFile . '.gz');
}

$phar = new Phar($buildRoot . $pharFile, 0, $pharFile);

$phar["index.php"]            = file_get_contents($srcRoot . "index.php");
$phar["BaseEventHandler.php"] = file_get_contents($srcRoot . "BaseEventHandler.php");
$phar["Handler.php"]          = file_get_contents($srcRoot . "Handler.php");
$phar["AbstractHandler.php"]  = file_get_contents($srcRoot . "AbstractHandler.php");
$phar["Loop.php"]             = file_get_contents($srcRoot . "Loop.php");
$phar["AbstractLoop.php"]     = file_get_contents($srcRoot . "AbstractLoop.php");
$phar[".htaccess"]            = file_get_contents($srcRoot . ".htaccess");

$phar["functions.php"]      = file_get_contents($srcRoot . "functions.php");
$phar["Launch.php"]         = file_get_contents($srcRoot . "Launch.php");
$phar["FilteredLogger.php"] = file_get_contents($srcRoot . "FilteredLogger.php");
$phar["UserDate.php"]       = file_get_contents($srcRoot . "UserDate.php");

$phar->setStub($phar->createDefaultStub("index.php"));

copy($srcRoot . "config.sample.php",           $buildRoot . "config.sample.php");

if (!file_exists('build/handlers.sample')) {
    mkdir('build/handlers.sample');
}
copy($srcRoot . "handlers/BuiltinHandler.php", $buildRoot . "handlers.sample/BuiltinHandler.php");
copy($srcRoot . "handlers/YourHandler.php",    $buildRoot . "handlers.sample/YourHandler.php");

if (!file_exists('build/loops.sample')) {
    mkdir('build/loops.sample');
}
copy($srcRoot . "loops/BuiltinLoop.php",       $buildRoot . "loops.sample/BuiltinLoop.php");
copy($srcRoot . "loops/YourLoop.php",          $buildRoot . "loops.sample/YourLoop.php");

echo "$buildRoot$pharFile successfully created" . PHP_EOL;
