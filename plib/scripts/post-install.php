<?php
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');

// Ningún dominio se sincroniza hasta activarlo en los ajustes, así que registrar el backend es inocuo.
try {
    $cmd = substr(PHP_OS, 0, 3) === 'WIN' ? '"' . PRODUCT_ROOT . '\bin\extension.exe"' : '"' . PRODUCT_ROOT . '/bin/extension"';
    pm_ApiCli::call('server_dns', ['--enable-custom-backend', $cmd . ' --exec ' . pm_Context::getModuleId() . ' backend.php']);

    $scheduler = pm_Scheduler::getInstance();
    foreach ($scheduler->listTasks() as $existing) {
        if ($existing->getCmd() === 'retry.php') {
            exit(0);
        }
    }
    $task = new pm_Scheduler_Task();
    $task->setSchedule(pm_Scheduler::$EVERY_5_MIN);
    $task->setCmd('retry.php');
    $scheduler->putTask($task);
} catch (pm_Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
exit(0);
