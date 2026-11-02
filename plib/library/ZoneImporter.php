<?php
class Modules_DondominioDns_ZoneImporter
{
    public function __construct(private $client, private $plesk, private Modules_DondominioDns_Backups $backups) {}

    public function importAll(): array
    {
        $services = $this->client->listServices();
        $domains = $this->plesk->domains();
        $report = [
            'imported' => [], 'errors' => [],
            'servicesWithoutDomain' => array_values(array_diff($services, $domains)),
            'domainsWithoutService' => array_values(array_diff($domains, $services)),
        ];
        foreach (array_intersect($services, $domains) as $domain) {
            try {
                $this->importOne($domain);
                $report['imported'][] = $domain;
            } catch (Throwable $e) {
                $report['errors'][$domain] = $e->getMessage();
            }
        }
        return $report;
    }

    public function importOne(string $domain): void
    {
        $desired = array_values(array_filter(array_map(['Modules_DondominioDns_Record', 'fromDonDominio'], $this->client->listRecords($domain))));
        if (!$desired) {
            throw new Modules_DondominioDns_ApiException("$domain: la zona de Don Dominio no tiene registros sincronizables; no se toca Plesk");
        }
        $all = $this->plesk->records($domain);
        $current = array_values(array_filter(Modules_DondominioDns_Record::withoutNsGlue($all), fn($r) => Modules_DondominioDns_Record::isSupported($r['type'])));
        $plan = Modules_DondominioDns_ZoneDiff::plan($desired, $current);
        if (!$plan['create'] && !$plan['update'] && !$plan['delete']) {
            return;
        }
        $this->backups->save($domain, 'plesk', $all);
        foreach ($plan['create'] as $rec) {
            $this->plesk->addRecord($domain, $rec);
        }
        foreach ($plan['update'] as $u) {
            $this->plesk->deleteRecord($u['id']);
            $this->plesk->addRecord($domain, $u['record']);
        }
        foreach ($plan['delete'] as $rec) {
            $this->plesk->deleteRecord($rec['id']);
        }
    }
}
