<?php
class Modules_DondominioDns_Record
{
    private const SYNCED = ['A', 'AAAA', 'CNAME', 'MX', 'SRV', 'TXT', 'CAA'];
    private const HOST_VALUE = ['CNAME', 'MX'];

    public static function isSupported(string $type): bool
    {
        return in_array(strtoupper($type), self::SYNCED, true);
    }

    public static function fromPlesk(object $rr): ?array
    {
        $type = strtoupper((string)$rr->type);
        if (!self::isSupported($type)) {
            return null;
        }
        $opt = trim((string)($rr->opt ?? ''));
        $value = (string)$rr->value;
        $priority = '-';
        if ($type === 'MX') {
            $priority = $opt === '' ? '-' : $opt;
        } elseif ($type === 'SRV') {
            [$priority, $weight, $port] = array_pad(preg_split('/\s+/', $opt), 3, '0');
            $value = "$weight $port " . self::host($value);
        } elseif ($type === 'CAA' && $opt !== '') {
            $value = "$opt $value";
        }
        return self::make((string)$rr->host, $type, $priority, $value, null);
    }

    public static function fromDonDominio(array $r): ?array
    {
        $type = strtoupper((string)$r['type']);
        if (!self::isSupported($type)) {
            return null;
        }
        return self::make((string)$r['name'], $type, (string)$r['priority'], (string)$r['value'], (string)$r['entityID']);
    }

    public static function key(array $rec): string
    {
        return implode('|', [$rec['name'], $rec['type'], $rec['priority'], $rec['value']]);
    }

    private static function make(string $name, string $type, string $priority, string $value, ?string $id): array
    {
        if (in_array($type, self::HOST_VALUE, true)) {
            $value = self::host($value);
        }
        return ['name' => self::host($name), 'type' => $type, 'priority' => $priority === '' ? '-' : $priority, 'value' => trim($value), 'id' => $id];
    }

    private static function host(string $h): string
    {
        return strtolower(rtrim(trim($h), '.'));
    }
}
