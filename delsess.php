<?php
$head = "madeline.madeline";
$tails = ['', ".lock", ".ipc", ".callback.ipc", ".lightState.php", ".safe.php", ".ipcState.php"];
foreach ($tails as $tail) {
    $file = "$head$tail";
    if (file_exists("$file")) {
        unlink("$file");
    }
}

//https://www.php.net/manual/en/function.scandir.php#46872
function myscandir($dir, $exp, $how = 'name', $descending = 0)
{
    $r = array();
    $dh = @opendir($dir);
    if ($dh) {
        while (($fname = readdir($dh)) !== false) {
            if (preg_match($exp, $fname)) {
                $stat = stat("$dir/$fname");
                $r[$fname] = ($how === 'name') ? $fname : $stat[$how];
            }
        }
        closedir($dh);
        if ($descending) {
            arsort($r);
        } else {
            asort($r);
        }
    }
    return (array_keys($r));
}
//$r = myscandir('./book/', '/^article[0-9]{4}\.txt$/i', 'ctime', 1);
//print_r($r);
