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
            } catch (Exception $e) {
                $report['errors'][$domain] = $e->getMessage();
            }
        }
        return $report;
    }

    public function importOne(string $domain): void
    {
        $desired = array_values(array_filter(array_map(['Modules_DondominioDns_Record', 'fromDonDominio'], $this->client->listRecords($domain))));
        $current = $this->plesk->records($domain);
        $this->backups->save($domain, 'plesk', $current);
        $plan = Modules_DondominioDns_ZoneDiff::plan($desired, $current);
        foreach ($plan['create'] as $rec) {
            $this->plesk->addRecord($domain, $rec);
        }
        foreach ($plan['update'] as $u) {
            $this->plesk->addRecord($domain, $u['record']);
            $this->plesk->deleteRecord($u['id']);
        }
        foreach ($plan['delete'] as $rec) {
            $this->plesk->deleteRecord($rec['id']);
        }
    }
}
