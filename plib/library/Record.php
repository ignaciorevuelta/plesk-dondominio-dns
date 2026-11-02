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
        if (!self::isSupported($type)) {
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
        if (in_array($rec['type'], ['CNAME', 'MX', 'SRV'], true)) {
            $value .= '.';
        }
        return ['host' => $host, 'type' => $rec['type'], 'opt' => $opt, 'value' => $value];
    }

    // Quita los A/AAAA que dan dirección a los NS de la propia zona (ns1.dominio...). Plesk los exige
    // mientras tenga esos NS, y en Don Dominio sobran porque allí mandan sus propios NS.
    public static function withoutNsGlue(array $records): array
    {
        $targets = [];
        foreach ($records as $r) {
            if (strtoupper($r['type']) === 'NS') {
                $targets[self::host($r['value'])] = true;
            }
        }
        return array_values(array_filter($records, fn($r) => !(in_array(strtoupper($r['type']), ['A', 'AAAA'], true) && isset($targets[self::host($r['name'])]))));
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
