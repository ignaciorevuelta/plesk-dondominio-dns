<?php
class Modules_DondominioDns_SyncEngine
{
    public function __construct(private $client, private Modules_DondominioDns_Backups $backups, private int $maxDeletePercent = 30) {}

    // Cambios que sync() aplicaría, sin escribir nada. La vista previa usa esto.
    public function plan(string $domain, array $desired, ?array $known = null): array
    {
        return $this->compute($domain, $desired, $known)[0];
    }

    // $known: claves de los registros que esta extensión dejó en Don Dominio en la última sincronización.
    // Lo que no esté ahí se añadió fuera de Plesk: no se borra, se devuelve en 'foreign' para avisar.
    // null = sin historial (al activar, tras revisar la vista previa): se puede borrar lo que sobre.
    public function sync(string $domain, array $desired, bool $force = false, ?array $known = null): array
    {
        [$plan, $current, $raw] = $this->compute($domain, $desired, $known);
        $empty = !$plan['create'] && !$plan['update'] && !$plan['delete'];
        if ($empty) {
            return $plan;
        }
        if (!$force && (count($plan['delete']) + count($plan['update'])) * 100 > count($current) * $this->maxDeletePercent) {
            throw new Modules_DondominioDns_BlockedException(sprintf('%s: se borrarían o sustituirían %d de %d registros', $domain, count($plan['delete']) + count($plan['update']), count($current)));
        }
        $this->backups->save($domain, 'dondominio', $raw);
        $applied = [];
        try {
            foreach ($plan['create'] as $rec) {
                $this->client->create($domain, $rec);
                $applied[] = 'create:' . $rec['name'];
            }
            foreach ($plan['update'] as $u) {
                $this->client->update($domain, $u['id'], $u['record']);
                $applied[] = 'update:' . $u['id'];
            }
            foreach ($plan['delete'] as $rec) {
                $this->client->delete($domain, $rec['id']);
                $applied[] = 'delete:' . $rec['id'];
            }
        } catch (Throwable $e) {
            throw new Modules_DondominioDns_PartialSyncException(sprintf('%s: fallo a mitad de la sincronización (%d operaciones aplicadas): %s', $domain, count($applied), $e->getMessage()), $applied, $e);
        }
        return $plan;
    }

    // Claves que quedan en Don Dominio a cargo de la extensión tras aplicar un plan: lo deseado.
    public static function ownedKeys(array $desired, string $domain): array
    {
        return array_map(['Modules_DondominioDns_Record', 'key'], Modules_DondominioDns_Record::syncable($desired, $domain));
    }

    private function compute(string $domain, array $desired, ?array $known): array
    {
        $raw = $this->client->listRecords($domain);
        $current = Modules_DondominioDns_Record::syncable(array_values(array_filter(array_map(['Modules_DondominioDns_Record', 'fromDonDominio'], $raw))), $domain);
        $wanted = Modules_DondominioDns_Record::syncable($desired, $domain);
        $foreign = [];
        if ($known !== null) {
            // Los ajenos quedan fuera de la comparación: así no se borran ni se emparejan como «sustituir».
            // Si coinciden con algo de Plesk se tratan como propios, para no duplicarlos.
            $known = array_flip($known);
            $wantedKeys = array_flip(array_map(['Modules_DondominioDns_Record', 'key'], $wanted));
            $own = [];
            foreach ($current as $rec) {
                $key = Modules_DondominioDns_Record::key($rec);
                if (isset($known[$key]) || isset($wantedKeys[$key])) {
                    $own[] = $rec;
                } else {
                    $foreign[] = $rec;
                }
            }
            $current = $own;
        }
        $plan = Modules_DondominioDns_ZoneDiff::plan($wanted, $current);
        $plan['foreign'] = $foreign;
        $plan['skipped'] = Modules_DondominioDns_Record::unsyncable($desired, $domain);
        return [$plan, $current, $raw];
    }
}
