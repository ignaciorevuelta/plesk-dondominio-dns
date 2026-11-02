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
    public static function importDone(): bool { return pm_Settings::get('importDone', '') === '1'; }
    public static function setImportDone(): void { pm_Settings::set('importDone', '1'); }
    public static function enabled(): array { return self::json('enabled'); }
    public static function setEnabled(array $v): void { pm_Settings::set('enabled', json_encode(array_values(array_unique($v)))); }
    public static function report(): array { return self::json('report'); }
    public static function setReport(array $v): void { pm_Settings::set('report', json_encode($v)); }
    public static function failures(): array { return self::json('failures'); }
    public static function setFailures(array $v): void { pm_Settings::set('failures', json_encode($v)); }

    private static function json(string $key): array
    {
        return json_decode((string)pm_Settings::get($key, '[]'), true) ?: [];
    }
}
