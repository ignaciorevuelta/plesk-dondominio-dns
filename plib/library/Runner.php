<?php
// Ensambla las piezas con los ajustes de Plesk y gestiona la cola de reintentos.
class Modules_DondominioDns_Runner
{
    private const MAX_ATTEMPTS = 5;

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
        $desired = array_map(function ($r) { $r['id'] = null; return $r; }, (new Modules_DondominioDns_PleskDns())->records($domain));
        self::engine()->sync($domain, $desired, $force);
    }

    // Tareas que Plesk envía al backend. Plesk manda la zona completa en cada cambio.
    public static function handleTasks(array $tasks): void
    {
        self::locked(function () use ($tasks) {
            $enabled = Modules_DondominioDns_Config::enabled();
            foreach ($tasks as $task) {
                if (!in_array((string)($task->command ?? ''), ['create', 'update'], true)) {
                    continue;
                }
                $domain = strtolower(rtrim((string)($task->zone->name ?? ''), '.'));
                if (!in_array($domain, $enabled, true)) {
                    continue;
                }
                $desired = [];
                foreach ((array)($task->zone->rr ?? []) as $rr) {
                    $rec = Modules_DondominioDns_Record::fromPlesk($rr);
                    if ($rec === null) {
                        if (!in_array(strtoupper((string)$rr->type), ['NS', 'SOA'], true)) {
                            fwrite(STDERR, "$domain: registro {$rr->type} omitido, Don Dominio no lo admite\n");
                        }
                        continue;
                    }
                    $desired[] = $rec;
                }
                self::attempt($domain, fn() => self::engine()->sync($domain, $desired));
            }
        });
    }

    public static function retry(): void
    {
        self::locked(function () {
            foreach (Modules_DondominioDns_Config::failures() as $domain => $f) {
                if (!empty($f['blocked']) || $f['attempts'] >= self::MAX_ATTEMPTS || $f['next'] > time()) {
                    continue;
                }
                if (!in_array($domain, Modules_DondominioDns_Config::enabled(), true)) {
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

    // Evita que el backend y el reintento escriban a la vez en la misma zona o en la cola.
    private static function locked(callable $work): void
    {
        $h = fopen(pm_Context::getVarDir() . 'sync.lock', 'c');
        flock($h, LOCK_EX);
        try {
            $work();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private static function backups(): Modules_DondominioDns_Backups
    {
        return new Modules_DondominioDns_Backups(pm_Context::getVarDir() . 'backups');
    }
}
