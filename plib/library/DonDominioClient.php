<?php
class Modules_DondominioDns_DonDominioClient
{
    private const BASE = 'https://simple-api.dondominio.net/';
    private const PAGE = 1000;
    private $transport;

    public function __construct(private string $user, private string $pass, ?callable $transport = null)
    {
        $this->transport = $transport ?? [$this, 'curl'];
    }

    public function listServices(): array
    {
        $names = [];
        $page = 1;
        do {
            $d = $this->call('service/list', ['page' => $page, 'pageLength' => self::PAGE]);
            foreach ($d['services'] ?? [] as $s) {
                $names[] = $s['name'];
            }
            $total = (int)($d['queryInfo']['total'] ?? count($names));
            $page++;
        } while (count($names) < $total && !empty($d['services']));
        return $names;
    }

    public function listRecords(string $service): array
    {
        $records = [];
        $page = 1;
        do {
            $d = $this->call('service/dnslist', ['serviceName' => $service, 'page' => $page, 'pageLength' => self::PAGE]);
            $records = array_merge($records, $d['dns'] ?? []);
            $total = (int)($d['queryInfo']['total'] ?? count($records));
            $page++;
        } while (count($records) < $total && !empty($d['dns']));
        return $records;
    }

    public function create(string $service, array $rec): void
    {
        $this->call('service/dnscreate', ['serviceName' => $service, 'name' => $rec['name'], 'type' => $rec['type'], 'value' => $rec['value']] + $this->priority($rec));
    }

    public function update(string $service, string $id, array $rec): void
    {
        $this->call('service/dnsupdate', ['serviceName' => $service, 'entityID' => $id, 'value' => $rec['value']] + $this->priority($rec));
    }

    public function delete(string $service, string $id): void
    {
        $this->call('service/dnsdelete', ['serviceName' => $service, 'entityID' => $id]);
    }

    private function priority(array $rec): array
    {
        return $rec['priority'] === '-' ? [] : ['priority' => $rec['priority']];
    }

    private function call(string $action, array $params): array
    {
        $raw = ($this->transport)(self::BASE . $action . '/', $params + ['apiuser' => $this->user, 'apipasswd' => $this->pass]);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new Modules_DondominioDns_ApiException("$action: respuesta no válida");
        }
        if (empty($json['success'])) {
            throw new Modules_DondominioDns_ApiException(sprintf('%s: error %s %s', $action, $json['errorCode'] ?? '?', $json['errorCodeMsg'] ?? ''));
        }
        return is_array($json['responseData'] ?? null) ? $json['responseData'] : [];
    }

    private function curl(string $url, array $post): string
    {
        $h = curl_init($url);
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $out = curl_exec($h);
        $err = curl_error($h);
        curl_close($h);
        if ($out === false) {
            throw new Modules_DondominioDns_ApiException("Fallo de red: $err");
        }
        return $out;
    }
}
