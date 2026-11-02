<?php
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');

// Ningún dominio se sincroniza hasta activarlo en los ajustes, así que registrar el backend es inocuo.
$result = pm_ApiCli::call('server_dns', ['--enable-custom-backend', '/usr/local/psa/bin/extension --exec dondominio-dns backend.php'], pm_ApiCli::RESULT_FULL);
if ((int)$result['code'] !== 0) {
    echo "No se pudo registrar el backend DNS: {$result['stderr']}\n";
    exit(1);
}

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
exit(0);
