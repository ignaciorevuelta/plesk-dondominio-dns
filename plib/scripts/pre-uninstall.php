<?php
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');

try {
    $notice = Modules_DondominioDns_Backend::unregisterIfOurs();
    if ($notice !== null) {
        echo $notice . "\n";
    }
    pm_Scheduler::getInstance()->removeAllTasks();
} catch (pm_Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
exit(0);
