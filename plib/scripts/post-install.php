<?php
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');

try {
    // Solo se registra como backend DNS si no hay otro. Si lo hay, el administrador decide en los ajustes.
    $current = Modules_DondominioDns_Backend::current();
    if ($current === '' || Modules_DondominioDns_Backend::isOurs($current)) {
        Modules_DondominioDns_Backend::register();
    } else {
        echo "Another custom DNS backend is active, so it was left in place. Choose whether to replace it in the extension settings.\n";
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
} catch (pm_Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
exit(0);
