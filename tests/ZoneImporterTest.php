<?php
use PHPUnit\Framework\TestCase;
use Modules_DondominioDns_ZoneImporter as I;
use Modules_DondominioDns_Backups as B;

final class FakePlesk {
    public array $log = [];
    public function __construct(public array $zones) {}
    public function domains(){ return array_keys($this->zones); }
    public function records($d){ return $this->zones[$d]; }
    public function addRecord($d,$r){ $this->log[]="add:{$r['name']}"; }
    public function deleteRecord($id){ $this->log[]="del:$id"; }
}
final class FakeDd {
    public int $writes = 0;
    public function __construct(public array $z) {}
    public function listServices(){ return array_keys($this->z); }
    public function listRecords($s){ if ($this->z[$s]===null) throw new RuntimeException('boom'); return $this->z[$s]; }
    public function create(){ $this->writes++; } public function update(){ $this->writes++; } public function delete(){ $this->writes++; }
}

final class ZoneImporterTest extends TestCase {
    private function dd($n,$v){ return ['entityID'=>'x','name'=>$n,'type'=>'CNAME','ttl'=>'-','priority'=>'-','value'=>$v]; }
    private function rec($id,$n,$v){ return ['name'=>$n,'type'=>'CNAME','priority'=>'-','value'=>$v,'id'=>$id]; }

    public function testMakesPleskMatchDonDominioAndReports(): void {
        $p = new FakePlesk(['a.es'=>[$this->rec('p1','old.a.es','x')],'solo-plesk.es'=>[]]);
        $d = new FakeDd(['a.es'=>[$this->dd('auto.a.es','outlook.com')],'solo-dd.es'=>[]]);
        $r = (new I($d,$p,new B(sys_get_temp_dir().'/ddi'.uniqid())))->importAll();
        $this->assertSame(['a.es'],$r['imported']);
        $this->assertSame(['solo-dd.es'],$r['servicesWithoutDomain']);
        $this->assertSame(['solo-plesk.es'],$r['domainsWithoutService']);
        $this->assertSame(['add:auto.a.es','del:p1'],$p->log);
        $this->assertSame(0,$d->writes);
    }
    public function testErrorInOneDomainDoesNotStopOthers(): void {
        $p = new FakePlesk(['mal.es'=>[],'bien.es'=>[]]);
        $d = new FakeDd(['mal.es'=>null,'bien.es'=>[]]);
        $r = (new I($d,$p,new B(sys_get_temp_dir().'/ddi'.uniqid())))->importAll();
        $this->assertSame(['bien.es'],$r['imported']);
        $this->assertArrayHasKey('mal.es',$r['errors']);
    }
}
