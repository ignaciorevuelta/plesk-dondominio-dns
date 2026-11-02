<?php
// Zonas de Plesk por XML-RPC. records() devuelve los registros sincronizables normalizados
// y los demás (NS, PTR...) en bruto, para que las copias guarden la zona entera.
class Modules_DondominioDns_PleskDns
{
    private array $siteIds = [];

    public function domains(): array
    {
        $this->loadSites();
        return array_keys($this->siteIds);
    }

    public function records(string $domain): array
    {
        $r = $this->call('<dns><get_rec><filter><site-id>' . $this->siteId($domain) . '</site-id></filter></get_rec></dns>');
        $out = [];
        foreach ($r->dns->get_rec->result as $res) {
            if ((string)$res->status !== 'ok') {
                continue;
            }
            $d = $res->data;
            $rr = (object)['host' => (string)$d->host, 'type' => (string)$d->type, 'opt' => (string)$d->opt, 'value' => (string)$d->value];
            $rec = Modules_DondominioDns_Record::fromPlesk($rr) ?? ['name' => rtrim(strtolower($rr->host), '.'), 'type' => strtoupper($rr->type), 'priority' => '-', 'value' => $rr->value];
            $rec['id'] = (string)$res->id;
            $out[] = $rec;
        }
        return $out;
    }

    public function addRecord(string $domain, array $rec): void
    {
        $p = Modules_DondominioDns_Record::toPlesk($rec, $domain);
        $this->call(sprintf(
            '<dns><add_rec><site-id>%d</site-id><type>%s</type><host>%s</host><value>%s</value><opt>%s</opt></add_rec></dns>',
            $this->siteId($domain), $this->esc($p['type']), $this->esc($p['host']), $this->esc($p['value']), $this->esc($p['opt'])
        ), 'add_rec');
    }

    public function deleteRecord(string $id): void
    {
        if (!ctype_digit($id)) {
            throw new InvalidArgumentException("Id de registro no válido: $id");
        }
        $this->call("<dns><del_rec><filter><id>$id</id></filter></del_rec></dns>", 'del_rec');
    }

    private function siteId(string $domain): int
    {
        $this->loadSites();
        if (!isset($this->siteIds[$domain])) {
            throw new RuntimeException("$domain no existe en Plesk");
        }
        return $this->siteIds[$domain];
    }

    private function loadSites(): void
    {
        if ($this->siteIds) {
            return;
        }
        // Los dominios principales de cada suscripción son webspaces; los demás, sites.
        foreach (['webspace', 'site'] as $type) {
            $r = $this->call("<$type><get><filter/><dataset><gen_info/></dataset></get></$type>");
            foreach ($r->$type->get->result as $res) {
                $name = strtolower((string)$res->data->gen_info->{'ascii-name'} ?: (string)$res->data->gen_info->name);
                // Los sitios comodín (*.dominio) no tienen zona propia.
                if ((string)$res->status === 'ok' && strpos((string)$res->data->gen_info->name, '*') === false) {
                    $this->siteIds[$name] = (int)$res->id;
                }
            }
        }
    }

    // $op: nodo cuyo <result><status> debe ser ok (las lecturas devuelven varios results y se filtran aparte).
    private function call(string $xml, ?string $op = null): SimpleXMLElement
    {
        $r = pm_ApiRpc::getService()->call($xml);
        if ($op !== null) {
            $res = $r->dns->{$op}->result;
            if ((string)$res->status !== 'ok') {
                throw new RuntimeException("Plesk $op: " . (string)$res->errtext);
            }
        }
        return $r;
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
