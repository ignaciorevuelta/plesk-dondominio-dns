<?php
class Modules_DondominioDns_ZoneDiff
{
    public static function plan(array $desired, array $current): array
    {
        $missing = [];
        foreach ($desired as $rec) {
            $missing[Modules_DondominioDns_Record::key($rec)] = $rec;
        }
        $extra = [];
        foreach ($current as $rec) {
            $key = Modules_DondominioDns_Record::key($rec);
            if (isset($missing[$key])) {
                unset($missing[$key]);
            } else {
                $extra[] = $rec;
            }
        }

        // Emparejar por nombre y tipo evita dos CNAME simultáneos al cambiar un valor.
        $update = [];
        $create = [];
        foreach ($missing as $rec) {
            $found = null;
            foreach ($extra as $i => $old) {
                if ($old['name'] === $rec['name'] && $old['type'] === $rec['type']) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                $create[] = $rec;
            } else {
                $update[] = ['id' => $extra[$found]['id'], 'record' => $rec];
                unset($extra[$found]);
            }
        }
        return ['create' => $create, 'update' => $update, 'delete' => array_values($extra)];
    }
}
