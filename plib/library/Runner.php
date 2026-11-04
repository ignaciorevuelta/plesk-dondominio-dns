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

    // $reviewed: el administrador acaba de revisar la vista previa (activar o confirmar), así que se puede
    // borrar en Don Dominio lo que no esté en Plesk aunque no lo creara la extensión.
    public static function syncDomain(string $domain, bool $force = false, bool $reviewed = false): void
    {
        self::syncWith($domain, self::desired($domain), $force, $reviewed);
    }

    // Lo que la sincronización haría ahora mismo, con las mismas reglas que syncDomain.
    public static function preview(string $domain): array
    {
        $enabled = in_array($domain, Modules_DondominioDns_Config::enabled(), true);
        return self::engine()->plan($domain, self::desired($domain), $enabled ? Modules_DondominioDns_Config::known($domain) : null);
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
        $ran = self::locked(function () use ($zones) {
            foreach ($zones as $domain => $desired) {
                self::attempt($domain, fn() => self::syncWith($domain, $desired, false, false));
            }
        });
        if (!$ran) {
            // Otra sincronización sigue en curso: se deja anotado y la tarea de reintentos lo aplica.
            foreach (array_keys($zones) as $domain) {
                self::markPending($domain);
            }
        }
    }

    public static function retry(): void
    {
        if (!Modules_DondominioDns_Config::eulaAccepted()) {
            return;
        }
        self::locked(function () {
            $enabled = Modules_DondominioDns_Config::enabled();
            foreach (self::takePending() as $domain) {
                if (in_array($domain, $enabled, true)) {
                    self::attempt($domain, fn() => self::syncDomain($domain));
                }
            }
            foreach (Modules_DondominioDns_Config::failures() as $domain => $f) {
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

    private static function syncWith(string $domain, array $desired, bool $force, bool $reviewed): void
    {
        $known = $reviewed ? null : Modules_DondominioDns_Config::known($domain);
        $plan = self::engine()->sync($domain, $desired, $force, $known);
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

    // Evita que el backend y el reintento escriban a la vez. Devuelve false si no consigue el bloqueo a tiempo.
    private static function locked(callable $work): bool
    {
        $h = fopen(pm_Context::getVarDir() . 'sync.lock', 'c');
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

    // Un fichero por dominio: se puede anotar sin el bloqueo y sin pisar a nadie.
    private static function markPending(string $domain): void
    {
        $dir = pm_Context::getVarDir() . 'pending';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        touch($dir . '/' . basename($domain));
    }

    private static function takePending(): array
    {
        $domains = [];
        foreach (glob(pm_Context::getVarDir() . 'pending/*') ?: [] as $file) {
            $domains[] = basename($file);
            unlink($file);
        }
        return $domains;
    }

    private static function backups(): Modules_DondominioDns_Backups
    {
        return new Modules_DondominioDns_Backups(pm_Context::getVarDir() . 'backups');
    }
}
