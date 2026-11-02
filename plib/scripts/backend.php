<?php
// Backend DNS personalizado: Plesk envía por stdin un array JSON de tareas.
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');

$tasks = json_decode(file_get_contents('php://stdin'));
if (!is_array($tasks)) {
    fwrite(STDERR, "Invalid json data input\n");
    exit(1);
}
// Los fallos con Don Dominio van a la cola de reintentos: el guardado en Plesk no debe fallar por ellos.
try {
    Modules_DondominioDns_Runner::handleTasks($tasks);
} catch (Throwable $e) {
    fwrite(STDERR, 'dondominio-dns: ' . $e->getMessage() . "\n");
}
exit(0);
