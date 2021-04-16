<?php

declare(strict_types=1);

$srcRoot   = "";
$buildRoot = "build/";

deltree($builRoot);
mkdir($builRoot);

if (!file_exists('build')) {
    mkdir('build');
}

copy($srcRoot . "index.php",            $buildRoot . "index.php");
copy($srcRoot . "BaseEventHandler.php", $buildRoot . "BaseEventHandler.php");
copy($srcRoot . "handler.php",          $buildRoot . "Handler.php");
copy($srcRoot . "AbstractHandler.php",  $buildRoot . "AbstractHandler.php");
copy($srcRoot . "Loop.php",             $buildRoot . "Loop.php");
copy($srcRoot . "AbstractLoop.php",     $buildRoot . "AbstractLoop.php");
copy($srcRoot . ".htaccess",            $buildRoot . ".htaccess");

copy($srcRoot . "functions.php",      $buildRoot . "functions.php");
copy($srcRoot . "Launch.php",         $buildRoot . "Launch.php");
copy($srcRoot . "FilteredLogger.php", $buildRoot . "FilteredLogger.php");
copy($srcRoot . "UserDate.php",       $buildRoot . "UserDate.php");

copy($srcRoot . "config.sample.php", $buildRoot . "config.sample.php");

if (!file_exists('build/handlers.sample')) {
    mkdir('build/handlers.sample');
}
copy($srcRoot . "handlers/BuiltinHandler.php", $buildRoot . "handlers.sample/BuiltinHandler.php");
copy($srcRoot . "handlers/YourHandler.php",    $buildRoot . "handlers.sample/YourHandler.php");

if (!file_exists('build/loops.sample')) {
    mkdir('build/loops.sample');
}
copy($srcRoot . "loops/BuiltinLoop.php", $buildRoot . "loops.sample/BuiltinLoop.php");
copy($srcRoot . "loops/YourLoop.php",    $buildRoot . "loops.sample/YourLoop.php");

echo "$buildRoot successfully created" . PHP_EOL;


function delTree($dir)
{
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}
