<?php
class Modules_DondominioDns_Record
{
    private const SYNCED = ['A', 'AAAA', 'CNAME', 'MX', 'SRV', 'TXT', 'CAA'];
    private const HOST_VALUE = ['CNAME', 'MX', 'NS'];

    public static function isSupported(string $type): bool
    {
        return in_array(strtoupper($type), self::SYNCED, true);
    }

    // Normaliza tipos admitidos y NS (las delegaciones se sincronizan; los NS del apex los filtra syncable()).
    public static function fromPlesk(object $rr): ?array
    {
        $type = strtoupper((string)$rr->type);
        if (!self::isSupported($type) && $type !== 'NS') {
            return null;
        }
        $opt = trim((string)($rr->opt ?? ''));
        $value = (string)$rr->value;
        $priority = '-';
        if ($type === 'MX') {
            $priority = $opt === '' ? '-' : $opt;
        } elseif ($type === 'SRV') {
            $parts = $opt === '' ? [] : preg_split('/\s+/', $opt);
            if (count($parts) !== 3) {
                return null;
            }
            [$priority, $weight, $port] = $parts;
            $value = "$weight $port $value";
        } elseif ($type === 'CAA' && $opt !== '') {
            $value = "$opt $value";
        }
        return self::make((string)$rr->host, $type, $priority, $value, null);
    }

    public static function fromDonDominio(array $r): ?array
    {
        $type = strtoupper((string)$r['type']);
        if (!self::isSupported($type) && $type !== 'NS') {
            return null;
        }
        return self::make((string)$r['name'], $type, (string)$r['priority'], (string)$r['value'], (string)$r['entityID']);
    }

    // Inversa de fromPlesk: campos de <add_rec>, con host relativo al dominio ('' para el apex).
    public static function toPlesk(array $rec, string $domain): array
    {
        $domain = self::host($domain);
        $host = $rec['name'] === $domain ? '' : substr($rec['name'], 0, -strlen(".$domain"));
        $value = $rec['value'];
        $opt = '';
        if ($rec['type'] === 'MX') {
            $opt = $rec['priority'] === '-' ? '' : $rec['priority'];
        } elseif ($rec['type'] === 'SRV') {
            [$weight, $port, $target] = preg_split('/\s+/', $value, 3);
            $opt = ($rec['priority'] === '-' ? '0' : $rec['priority']) . " $weight $port";
            $value = $target;
        } elseif ($rec['type'] === 'CAA') {
            [$flag, $tag, $rest] = array_pad(preg_split('/\s+/', $value, 3), 3, '');
            $opt = "$flag $tag";
            $value = $rest;
        }
        if (in_array($rec['type'], ['CNAME', 'MX', 'SRV', 'NS'], true)) {
            $value .= '.';
        }
        return ['host' => $host, 'type' => $rec['type'], 'opt' => $opt, 'value' => $value];
    }

    // Registro en bruto para tipos que no se normalizan (DS, PTR, SOA...): sirve para copias y avisos.
    public static function rawPlesk(object $rr): array
    {
        return ['name' => self::host((string)$rr->host), 'type' => strtoupper((string)$rr->type), 'priority' => '-', 'value' => trim((string)$rr->value), 'id' => null];
    }

    // Lo que se sincroniza de una zona: tipos admitidos y delegaciones NS de subdominios. Quedan fuera
    // los NS del apex (en Don Dominio mandan los suyos) y los A/AAAA de esos NS, que Plesk exige pero
    // en Don Dominio sobran.
    public static function syncable(array $records, string $domain): array
    {
        $domain = self::host($domain);
        $glue = self::apexNsTargets($records, $domain);
        return array_values(array_filter($records, function ($r) use ($domain, $glue) {
            $type = strtoupper($r['type']);
            $name = self::host($r['name']);
            if (in_array($type, ['A', 'AAAA'], true) && isset($glue[$name])) {
                return false;
            }
            return self::isSupported($type) || ($type === 'NS' && $name !== $domain);
        }));
    }

    // Registros que no se pueden sincronizar y que el administrador debe ver (DS, PTR...).
    public static function unsyncable(array $records, string $domain): array
    {
        $domain = self::host($domain);
        $glue = self::apexNsTargets($records, $domain);
        return array_values(array_filter($records, function ($r) use ($domain, $glue) {
            $type = strtoupper($r['type']);
            $name = self::host($r['name']);
            $expected = $type === 'SOA' || ($type === 'NS' && $name === $domain) || (in_array($type, ['A', 'AAAA'], true) && isset($glue[$name]));
            return !$expected && !self::isSupported($type) && $type !== 'NS';
        }));
    }

    private static function apexNsTargets(array $records, string $domain): array
    {
        $targets = [];
        foreach ($records as $r) {
            if (strtoupper($r['type']) === 'NS' && self::host($r['name']) === $domain) {
                $targets[self::host($r['value'])] = true;
            }
        }
        return $targets;
    }

    public static function zoneApexes(array $names): array
    {
        $set = array_flip($names);
        return array_values(array_filter($names, function ($n) use ($set) {
            for ($p = strpos($n, '.'); $p !== false; $p = strpos($n, '.', $p + 1)) {
                if (isset($set[substr($n, $p + 1)])) {
                    return false;
                }
            }
            return true;
        }));
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
        if ($type === 'SRV') {
            $tokens = preg_split('/\s+/', trim($value));
            $tokens[count($tokens) - 1] = self::host($tokens[count($tokens) - 1]);
            $value = implode(' ', $tokens);
        }
        return ['name' => self::host($name), 'type' => $type, 'priority' => $priority === '' ? '-' : $priority, 'value' => trim($value), 'id' => $id];
    }

    private static function host(string $h): string
    {
        return strtolower(rtrim(trim($h), '.'));
    }
}
