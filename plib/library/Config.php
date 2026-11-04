<?php
// Ajustes guardados con pm_Settings; la contraseña, cifrada.
class Modules_DondominioDns_Config
{
    public static function user(): string { return (string)pm_Settings::get('user', ''); }
    public static function pass(): string { return (string)pm_Settings::getDecrypted('pass', ''); }
    public static function setCredentials(string $user, #[\SensitiveParameter] string $pass): void
    {
        pm_Settings::set('user', $user);
        pm_Settings::setEncrypted('pass', $pass);
    }
    public static function threshold(): int { return (int)pm_Settings::get('threshold', 30); }
    public static function setThreshold(int $v): void { pm_Settings::set('threshold', (string)max(0, min(100, $v))); }
    public static function eulaAccepted(): bool { return pm_Settings::get('eulaAccepted', '') !== ''; }
    public static function setEulaAccepted(string $by): void { pm_Settings::set('eulaAccepted', json_encode(['by' => $by, 'at' => gmdate('c')])); }
    public static function importDone(): bool { return pm_Settings::get('importDone', '') === '1'; }
    public static function setImportDone(): void { pm_Settings::set('importDone', '1'); }
    public static function enabled(): array { return self::json('enabled'); }
    public static function setEnabled(array $v): void { pm_Settings::set('enabled', json_encode(array_values(array_unique($v)))); }
    public static function report(): array { return self::json('report'); }
    public static function setReport(array $v): void { pm_Settings::set('report', json_encode($v)); }
    // Claves de los registros que la extensión dejó en Don Dominio, por dominio (ver SyncEngine::sync).
    public static function known(string $domain): ?array { return self::json('known')[$domain] ?? null; }
    public static function setKnown(string $domain, ?array $keys): void
    {
        $all = self::json('known');
        if ($keys === null) { unset($all[$domain]); } else { $all[$domain] = array_values($keys); }
        pm_Settings::set('known', json_encode($all));
    }
    // Avisos por dominio: registros no sincronizables y registros ajenos conservados en Don Dominio.
    public static function notices(): array { return self::json('notices'); }
    public static function setNotice(string $domain, array $plan): void
    {
        $all = self::json('notices');
        $describe = fn($r) => trim("{$r['name']} {$r['type']} {$r['value']}");
        $notice = ['skipped' => array_map($describe, $plan['skipped'] ?? []), 'foreign' => array_map($describe, $plan['foreign'] ?? [])];
        if ($notice['skipped'] || $notice['foreign']) { $all[$domain] = $notice; } else { unset($all[$domain]); }
        pm_Settings::set('notices', json_encode($all));
    }
    public static function failures(): array { return self::json('failures'); }
    public static function setFailures(array $v): void { pm_Settings::set('failures', json_encode($v)); }

    private static function json(string $key): array
    {
        return json_decode((string)pm_Settings::get($key, '[]'), true) ?: [];
    }
}
