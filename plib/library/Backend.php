<?php
// Registro de la extensión como backend DNS personalizado. Plesk admite uno solo, así que nunca
// sustituye ni desactiva uno ajeno sin una decisión expresa del administrador.
class Modules_DondominioDns_Backend
{
    private const MARK = '--exec dondominio-dns backend.php';

    public static function command(): string
    {
        $bin = substr(PHP_OS, 0, 3) === 'WIN' ? '"' . PRODUCT_ROOT . '\bin\extension.exe"' : '"' . PRODUCT_ROOT . '/bin/extension"';
        return $bin . ' --exec ' . pm_Context::getModuleId() . ' backend.php';
    }

    // Script del backend activo: '' si no hay ninguno, null si no se puede leer.
    // server_dns no informa del backend activo; se lee la configuración de Plesk solo para comprobarlo.
    public static function current(): ?string
    {
        try {
            $value = pm_Bootstrap::getDbAdapter()->fetchOne(
                "SELECT value FROM ServiceNodeConfiguration WHERE section = 'dnsConnector' AND name = 'custom_script'"
            );
            return $value === false || $value === null ? '' : (string)$value;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function isOurs(?string $current): bool
    {
        return $current !== null && strpos($current, self::MARK) !== false;
    }

    public static function register(): void
    {
        pm_ApiCli::call('server_dns', ['--enable-custom-backend', self::command()]);
        pm_Settings::set('backendRegistered', '1');
    }

    // Desactiva el backend solo si es el de esta extensión. Si no se puede comprobar, se fía de si lo registró ella.
    public static function unregisterIfOurs(): void
    {
        $current = self::current();
        $ours = $current === null ? pm_Settings::get('backendRegistered', '') === '1' : self::isOurs($current);
        if ($ours) {
            pm_ApiCli::call('server_dns', ['--disable-custom-backend']);
        }
        pm_Settings::set('backendRegistered', '');
    }
}
