<?php // plib/library/SyncEngine.php
class Modules_DondominioDns_SyncEngine
{
    public function __construct(private $client, private Modules_DondominioDns_Backups $backups, private int $maxDeletePercent = 30) {}

    public function sync(string $domain, array $desired, bool $force = false): array
    {
        $raw = $this->client->listRecords($domain);
        $current = array_values(array_filter(array_map(['Modules_DondominioDns_Record', 'fromDonDominio'], $raw)));
        $desired = array_values(array_filter($desired, fn($r) => Modules_DondominioDns_Record::isSupported($r['type'])));
        $plan = Modules_DondominioDns_ZoneDiff::plan($desired, $current);
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
}
