<?php

require(dirname(dirname(__DIR__)) ."/bin/Worker.php");

Worker::debugLog("task1.php");
$config = fread(STDIN,1024);
Worker::debugLog($config);

?>