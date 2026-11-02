<?php // plib/library/SyncEngine.php
class Modules_DondominioDns_SyncEngine
{
    public function __construct(private $client, private Modules_DondominioDns_Backups $backups, private int $maxDeletePercent = 30) {}

    public function sync(string $domain, array $desired, bool $force = false): array
    {
        $raw = $this->client->listRecords($domain);
        $current = array_values(array_filter(array_map(['Modules_DondominioDns_Record', 'fromDonDominio'], $raw)));
        $plan = Modules_DondominioDns_ZoneDiff::plan($desired, $current);
        $empty = !$plan['create'] && !$plan['update'] && !$plan['delete'];
        if ($empty) {
            return $plan;
        }
        if (!$force && count($plan['delete']) * 100 > count($current) * $this->maxDeletePercent) {
            throw new Modules_DondominioDns_BlockedException(sprintf('%s: se borrarían %d de %d registros', $domain, count($plan['delete']), count($current)));
        }
        $this->backups->save($domain, 'dondominio', $raw);
        foreach ($plan['create'] as $rec) {
            $this->client->create($domain, $rec);
        }
        foreach ($plan['update'] as $u) {
            $this->client->update($domain, $u['id'], $u['record']);
        }
        foreach ($plan['delete'] as $rec) {
            $this->client->delete($domain, $rec['id']);
        }
        return $plan;
    }
}
