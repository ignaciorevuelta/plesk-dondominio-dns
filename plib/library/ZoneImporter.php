<?php
// Copia a Plesk las zonas de Don Dominio. Nunca borra un registro que solo exista en Plesk:
// lo conserva y lo devuelve en 'kept', para que el administrador lo vea en el informe y en la vista previa.
class Modules_DondominioDns_ZoneImporter
{
    public function __construct(private $client, private $plesk, private Modules_DondominioDns_Backups $backups) {}

    public function importAll(): array
    {
        $services = $this->client->listServices();
        $domains = $this->plesk->domains();
        $report = [
            'imported' => [], 'errors' => [], 'kept' => [], 'replaced' => [],
            'servicesWithoutDomain' => array_values(array_diff($services, $domains)),
            'domainsWithoutService' => array_values(array_diff($domains, $services)),
        ];
        foreach (array_intersect($services, $domains) as $domain) {
            try {
                $result = $this->importOne($domain);
                $report['imported'][] = $domain;
                foreach (['kept', 'replaced'] as $k) {
                    if ($result[$k]) {
                        $report[$k][$domain] = $result[$k];
                    }
                }
            } catch (Throwable $e) {
                $report['errors'][$domain] = $e->getMessage();
            }
        }
        return $report;
    }

    // Devuelve ['kept' => registros solo de Plesk, conservados; 'replaced' => valores de Plesk sustituidos].
    public function importOne(string $domain): array
    {
        $desired = Modules_DondominioDns_Record::syncable(array_values(array_filter(array_map(['Modules_DondominioDns_Record', 'fromDonDominio'], $this->client->listRecords($domain)))), $domain);
        if (!$desired) {
            throw new Modules_DondominioDns_ApiException("$domain: la zona de Don Dominio no tiene registros sincronizables; no se toca Plesk");
        }
        $all = $this->plesk->records($domain);
        $current = Modules_DondominioDns_Record::syncable($all, $domain);
        $plan = Modules_DondominioDns_ZoneDiff::plan($desired, $current);
        $result = ['kept' => array_map([self::class, 'describe'], $plan['delete']), 'replaced' => []];
        if (!$plan['create'] && !$plan['update']) {
            return $result;
        }
        $this->backups->save($domain, 'plesk', $all);
        foreach ($plan['create'] as $rec) {
            $this->plesk->addRecord($domain, $rec);
        }
        $byId = array_column($current, null, 'id');
        foreach ($plan['update'] as $u) {
            $result['replaced'][] = self::describe($byId[$u['id']]) . ' → ' . $u['record']['value'];
            $this->plesk->deleteRecord($u['id']);
            $this->plesk->addRecord($domain, $u['record']);
        }
        return $result;
    }

    private static function describe(array $rec): string
    {
        return trim("{$rec['name']} {$rec['type']} " . ($rec['priority'] !== '-' ? $rec['priority'] . ' ' : '') . $rec['value']);
    }
}
