<?php
class Modules_DondominioDns_DonDominioClient
{
    private const BASE = 'https://simple-api.dondominio.net/';
    private const PAGE = 1000;
    private $transport;

    public function __construct(private string $user, #[\SensitiveParameter] private string $pass, ?callable $transport = null)
    {
        $this->transport = $transport ?? [$this, 'curl'];
    }

    public function listServices(): array
    {
        return array_map(fn($s) => $s['name'], $this->readAll('service/list', [], 'services'));
    }

    public function listRecords(string $service): array
    {
        return $this->readAll('service/dnslist', ['serviceName' => $service], 'dns');
    }

    private function readAll(string $action, array $params, string $key): array
    {
        $items = [];
        $page = 1;
        do {
            $d = $this->call($action, $params + ['page' => $page, 'pageLength' => self::PAGE]);
            if (!is_array($d) || !isset($d[$key]) || !is_array($d[$key])) {
                throw new Modules_DondominioDns_ApiException("$action: respuesta sin '$key'");
            }
            $items = array_merge($items, $d[$key]);
            $total = (int)($d['queryInfo']['total'] ?? count($items));
            $page++;
        } while (count($items) < $total && $d[$key]);
        if (count($items) < $total) {
            throw new Modules_DondominioDns_ApiException(sprintf('%s: lista incompleta (%d de %d)', $action, count($items), $total));
        }
        return $items;
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

    private function call(string $action, array $params)
    {
        $raw = ($this->transport)(self::BASE . $action . '/', $params + ['apiuser' => $this->user, 'apipasswd' => $this->pass]);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new Modules_DondominioDns_ApiException("$action: respuesta no válida");
        }
        if (empty($json['success'])) {
            throw new Modules_DondominioDns_ApiException(sprintf('%s: error %s %s', $action, $json['errorCode'] ?? '?', $json['errorCodeMsg'] ?? ''));
        }
        return $json['responseData'] ?? null;
    }

    private function curl(string $url, array $post): string
    {
        $h = curl_init($url);
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
        $out = curl_exec($h);
        $err = curl_error($h);
        curl_close($h);
        if ($out === false) {
            throw new Modules_DondominioDns_ApiException("Fallo de red: $err");
        }
        return $out;
    }
}
