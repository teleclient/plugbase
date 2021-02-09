<?php
$head = "madeline.madeline";
$tails = ['', ".lock", ".ipc", ".callback.ipc", ".lightState.php", ".safe.php"];
foreach ($tails as $tail) {
    $file = "$head$tail";
    if (file_exists("$file")) {
        unlink("$file");
    }
}
