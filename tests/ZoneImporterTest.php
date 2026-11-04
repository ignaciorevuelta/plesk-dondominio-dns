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
    public function listRecords($s){ if ($this->z[$s]===null) throw new RuntimeException('boom'); if ($this->z[$s] instanceof Throwable) throw $this->z[$s]; return $this->z[$s]; }
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
        $this->assertSame(['add:auto.a.es'],$p->log);
        $this->assertSame(['a.es'=>['old.a.es CNAME x']],$r['kept']);
        $this->assertSame(0,$d->writes);
    }
    public function testNsGlueAddressInPleskIsKept(): void {
        $p = new FakePlesk(['a.es'=>[
            ['name'=>'a.es','type'=>'NS','priority'=>'-','value'=>'ns2.a.es.','id'=>'n1'],
            ['name'=>'ns2.a.es','type'=>'A','priority'=>'-','value'=>'192.0.2.1','id'=>'g1'],
            ['name'=>'old.a.es','type'=>'A','priority'=>'-','value'=>'192.0.2.9','id'=>'o1'],
        ]]);
        $d = new FakeDd(['a.es'=>[$this->dd('w.a.es','a.es')]]);
        (new I($d,$p,new B(sys_get_temp_dir().'/ddi'.uniqid())))->importOne('a.es');
        $this->assertSame(['add:w.a.es'],$p->log);
    }
    public function testErrorInOneDomainDoesNotStopOthers(): void {
        $p = new FakePlesk(['mal.es'=>[],'bien.es'=>[]]);
        $d = new FakeDd(['mal.es'=>null,'bien.es'=>[$this->dd('w.bien.es','x')]]);
        $r = (new I($d,$p,new B(sys_get_temp_dir().'/ddi'.uniqid())))->importAll();
        $this->assertSame(['bien.es'],$r['imported']);
        $this->assertArrayHasKey('mal.es',$r['errors']);
    }

    private function tmp(): string { return sys_get_temp_dir().'/ddi'.uniqid(); }

    public function testEmptyDonDominioZoneIsAnErrorAndPleskUntouched(): void {
        $p = new FakePlesk(['a.es'=>[$this->rec('p1','w.a.es','x')]]);
        $d = new FakeDd(['a.es'=>[]]);
        $r = (new I($d,$p,new B($this->tmp())))->importAll();
        $this->assertSame([],$r['imported']);
        $this->assertArrayHasKey('a.es',$r['errors']);
        $this->assertSame([],$p->log);
    }
    public function testZoneWithOnlyUnsupportedRecordsIsAnError(): void {
        $p = new FakePlesk(['a.es'=>[$this->rec('p1','w.a.es','x')]]);
        $d = new FakeDd(['a.es'=>[['entityID'=>'n','name'=>'a.es','type'=>'NS','ttl'=>'-','priority'=>'-','value'=>'ns1.x']]]);
        $r = (new I($d,$p,new B($this->tmp())))->importAll();
        $this->assertArrayHasKey('a.es',$r['errors']);
        $this->assertSame([],$p->log);
    }
    public function testPleskNsIsNeverDeleted(): void {
        $ns = ['name'=>'a.es','type'=>'NS','priority'=>'-','value'=>'ns1.plesk','id'=>'ns1'];
        $p = new FakePlesk(['a.es'=>[$ns, $this->rec('p1','w.a.es','x')]]);
        $d = new FakeDd(['a.es'=>[$this->dd('w.a.es','x')]]);
        (new I($d,$p,new B($this->tmp())))->importAll();
        $this->assertSame([],$p->log);
    }
    public function testUpdateDeletesBeforeAddingAndIsReported(): void {
        $p = new FakePlesk(['a.es'=>[$this->rec('p1','w.a.es','old')]]);
        $d = new FakeDd(['a.es'=>[$this->dd('w.a.es','new')]]);
        $r = (new I($d,$p,new B($this->tmp())))->importAll();
        $this->assertSame(['del:p1','add:w.a.es'],$p->log);
        $this->assertSame(['a.es'=>['w.a.es CNAME old → new']],$r['replaced']);
    }
    public function testNoBackupWhenPlanIsEmpty(): void {
        $dir = $this->tmp();
        $p = new FakePlesk(['a.es'=>[$this->rec('p1','w.a.es','x')]]);
        $d = new FakeDd(['a.es'=>[$this->dd('w.a.es','x')]]);
        $r = (new I($d,$p,new B($dir)))->importAll();
        $this->assertSame(['a.es'],$r['imported']);
        $this->assertSame([],glob($dir.'/a.es/*.json') ?: []);
    }
    public function testThrowableIsCaughtPerDomain(): void {
        $p = new FakePlesk(['mal.es'=>[],'bien.es'=>[]]);
        $d = new FakeDd(['mal.es'=>new TypeError('x'),'bien.es'=>[$this->dd('w.bien.es','x')]]);
        $r = (new I($d,$p,new B($this->tmp())))->importAll();
        $this->assertSame(['bien.es'],$r['imported']);
        $this->assertArrayHasKey('mal.es',$r['errors']);
    }
}
