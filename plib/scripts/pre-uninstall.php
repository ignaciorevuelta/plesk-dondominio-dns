<?php
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');

try {
    pm_ApiCli::call('server_dns', ['--disable-custom-backend']);
    pm_Scheduler::getInstance()->removeAllTasks();
} catch (pm_Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
exit(0);
