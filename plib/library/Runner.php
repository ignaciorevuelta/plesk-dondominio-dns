<?php
// Ensambla las piezas con los ajustes de Plesk y gestiona la cola de reintentos.
class Modules_DondominioDns_Runner
{
    private const MAX_ATTEMPTS = 5;
    // Plesk espera al backend al guardar una zona: nunca se le hace esperar más que esto por el bloqueo.
    private const LOCK_WAIT_SECONDS = 10;

    public static function client(): Modules_DondominioDns_DonDominioClient
    {
        return new Modules_DondominioDns_DonDominioClient(Modules_DondominioDns_Config::user(), Modules_DondominioDns_Config::pass());
    }

    public static function engine(): Modules_DondominioDns_SyncEngine
    {
        return new Modules_DondominioDns_SyncEngine(self::client(), self::backups(), Modules_DondominioDns_Config::threshold());
    }

    public static function importer(): Modules_DondominioDns_ZoneImporter
    {
        return new Modules_DondominioDns_ZoneImporter(self::client(), new Modules_DondominioDns_PleskDns(), self::backups());
    }

    public static function syncDomain(string $domain, bool $force = false): void
    {
        self::syncWith($domain, self::desired($domain), $force);
    }

    // Lo que la sincronización haría ahora mismo: mismas reglas que syncDomain, así lo que se ve es lo que se aplica.
    public static function preview(string $domain): array
    {
        return self::engine()->plan($domain, self::desired($domain), self::knownFor($domain));
    }

    // Dominio aún sin activar: el administrador revisa la vista previa antes de activarlo, así que se puede borrar
    // en Don Dominio lo que no esté en Plesk (null). Dominio activado: solo se borra lo que dejó la extensión; sin
    // historial (activado con una versión anterior) no cuenta nada como propio ([]).
    private static function knownFor(string $domain): ?array
    {
        if (!in_array($domain, Modules_DondominioDns_Config::enabled(), true)) {
            return null;
        }
        return Modules_DondominioDns_Config::known($domain) ?? [];
    }

    // Zona de Plesk como zona deseada (sin ids de Plesk, que no significan nada en Don Dominio).
    public static function desired(string $domain): array
    {
        return array_map(function ($r) { $r['id'] = null; return $r; }, (new Modules_DondominioDns_PleskDns())->records($domain));
    }

    // Tareas que Plesk envía al backend. Plesk manda la zona completa en cada cambio.
    public static function handleTasks(array $tasks): void
    {
        if (!Modules_DondominioDns_Config::eulaAccepted()) {
            return;
        }
        $enabled = Modules_DondominioDns_Config::enabled();
        $zones = [];
        foreach ($tasks as $task) {
            $domain = strtolower(rtrim((string)($task->zone->name ?? ''), '.'));
            if (in_array((string)($task->command ?? ''), ['create', 'update'], true) && in_array($domain, $enabled, true)) {
                $zones[$domain] = array_map(
                    fn($rr) => Modules_DondominioDns_Record::fromPlesk($rr) ?? Modules_DondominioDns_Record::rawPlesk($rr),
                    (array)($task->zone->rr ?? [])
                );
            }
        }
        if (!$zones) {
            return;
        }
        // Plesk espera al backend al guardar la zona: aquí solo se anota y la API de Don Dominio se llama
        // en segundo plano, para que una API lenta nunca retrase a Plesk.
        foreach ($zones as $domain => $desired) {
            self::markPending($domain, $desired);
        }
        self::retryInBackground();
    }

    private static function retryInBackground(): void
    {
        $cmd = escapeshellarg(PRODUCT_ROOT . '/bin/extension') . ' --exec ' . escapeshellarg(pm_Context::getModuleId()) . ' retry.php';
        exec($cmd . ' > /dev/null 2>&1 &');
    }

    public static function retry(): void
    {
        if (!Modules_DondominioDns_Config::eulaAccepted()) {
            return;
        }
        self::locked(function () {
            $enabled = Modules_DondominioDns_Config::enabled();
            $done = [];
            foreach (self::takePending() as $domain => $desired) {
                if (in_array($domain, $enabled, true)) {
                    self::attempt($domain, fn() => self::syncWith($domain, $desired, false));
                    $done[$domain] = true;
                }
                self::releasePending($domain);
            }
            foreach (Modules_DondominioDns_Config::failures() as $domain => $f) {
                if (isset($done[$domain])) {
                    continue;
                }
                if (!empty($f['blocked']) || $f['attempts'] >= self::MAX_ATTEMPTS || $f['next'] > time()) {
                    continue;
                }
                if (!in_array($domain, $enabled, true)) {
                    self::forget($domain);
                    continue;
                }
                self::attempt($domain, fn() => self::syncDomain($domain));
            }
        });
    }

    public static function forget(string $domain): void
    {
        $failures = Modules_DondominioDns_Config::failures();
        unset($failures[$domain]);
        Modules_DondominioDns_Config::setFailures($failures);
    }

    private static function syncWith(string $domain, array $desired, bool $force): void
    {
        $plan = self::engine()->sync($domain, $desired, $force, self::knownFor($domain));
        Modules_DondominioDns_Config::setKnown($domain, Modules_DondominioDns_SyncEngine::ownedKeys($desired, $domain));
        Modules_DondominioDns_Config::setNotice($domain, $plan);
    }

    private static function attempt(string $domain, callable $work): void
    {
        $failures = Modules_DondominioDns_Config::failures();
        try {
            $work();
            unset($failures[$domain]);
        } catch (Modules_DondominioDns_BlockedException $e) {
            $failures[$domain] = ['blocked' => true, 'attempts' => 0, 'next' => 0, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $attempts = ($failures[$domain]['attempts'] ?? 0) + 1;
            $failures[$domain] = ['blocked' => false, 'attempts' => $attempts, 'next' => time() + 60 * 2 ** $attempts, 'error' => $e->getMessage()];
            fwrite(STDERR, "$domain: {$e->getMessage()}\n");
        }
        Modules_DondominioDns_Config::setFailures($failures);
    }

    // Evita que el reintento y las acciones de la pantalla escriban a la vez en Don Dominio y en los ajustes.
    // Devuelve false si no consigue el bloqueo a tiempo.
    public static function locked(callable $work): bool
    {
        $h = @fopen(pm_Context::getVarDir() . 'sync.lock', 'c');
        if ($h === false) {
            return false;
        }
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        while (!flock($h, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($h);
                return false;
            }
            usleep(200000);
        }
        try {
            $work();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
        return true;
    }

    // Cola de cambios: pending/<dominio> guarda la última zona recibida de Plesk. Los temporales y los
    // reclamados van en directorios aparte, para no confundirlos nunca con un nombre de dominio.
    private static function queueDir(string $name): string
    {
        $dir = pm_Context::getVarDir() . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private static function markPending(string $domain, array $desired): void
    {
        // Temporal único por escritor; rename() es atómico, así que el lector nunca ve un fichero a medias.
        $tmp = self::queueDir('pending-tmp') . '/' . getmypid() . '-' . bin2hex(random_bytes(4));
        $file = self::queueDir('pending') . '/' . basename($domain);
        if (file_put_contents($tmp, json_encode($desired)) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            fwrite(STDERR, "$domain: no se pudo dejar el cambio en cola\n");
        }
    }

    // Solo se llama dentro de locked(), así que hay un único lector a la vez.
    private static function takePending(): array
    {
        $pending = self::queueDir('pending');
        $claimed = self::queueDir('pending-claimed');
        // Un reclamado que sigue aquí es de una ejecución interrumpida: vuelve a la cola salvo que ya haya uno más nuevo.
        foreach (glob($claimed . '/*') ?: [] as $old) {
            // link() falla si ya hay uno más nuevo: así nunca se sobrescribe. En los dos casos el reclamado sobra.
            @link($old, $pending . '/' . basename($old));
            unlink($old);
        }
        $zones = [];
        foreach (glob($pending . '/*') ?: [] as $file) {
            // Se reclama con rename() antes de leer: si llega una zona más nueva, queda en la cola para la próxima vez.
            $claim = $claimed . '/' . basename($file);
            if (!@rename($file, $claim)) {
                continue;
            }
            $desired = json_decode((string)file_get_contents($claim), true);
            if (is_array($desired)) {
                $zones[basename($file)] = $desired;
            }
        }
        return $zones;
    }

    // Se borra el reclamado solo cuando el cambio ya se ha intentado; un fallo queda en la cola de reintentos.
    private static function releasePending(string $domain): void
    {
        @unlink(pm_Context::getVarDir() . 'pending-claimed/' . basename($domain));
    }

    private static function backups(): Modules_DondominioDns_Backups
    {
        return new Modules_DondominioDns_Backups(pm_Context::getVarDir() . 'backups');
    }
}
