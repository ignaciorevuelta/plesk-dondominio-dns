<?php
use PHPUnit\Framework\TestCase;
use Modules_DondominioDns_SyncEngine as S;
use Modules_DondominioDns_Backups as B;

final class FakeClient {
    public array $log = [];
    public function __construct(public array $records) {}
    public function listRecords($s){ return $this->records; }
    public function create($s,$r){ $this->log[]='create:'.$r['name']; }
    public function update($s,$id,$r){ $this->log[]="update:$id"; }
    public function delete($s,$id){ $this->log[]="delete:$id"; }
}

final class SyncEngineTest extends TestCase {
    private string $dir;
    protected function setUp(): void { $this->dir = sys_get_temp_dir().'/ddtest'.uniqid(); }
    private function dd($id,$n,$v,$t='A'){ return ['entityID'=>$id,'name'=>$n,'type'=>$t,'ttl'=>'-','priority'=>'-','value'=>$v]; }
    private function rec($n,$v){ return ['name'=>$n,'type'=>'A','priority'=>'-','value'=>$v,'id'=>null]; }

    public function testCreatesBeforeDeletes(): void {
        $c = new FakeClient([$this->dd('1','a','1'),$this->dd('2','b','2'),$this->dd('3','c','3'),$this->dd('4','d','4')]);
        (new S($c,new B($this->dir)))->sync('ej.es',[$this->rec('a','1'),$this->rec('b','2'),$this->rec('c','3'),$this->rec('z','9')]);
        $this->assertSame(['create:z','delete:4'],$c->log);
    }
    public function testBlocksMassDeleteWithoutWriting(): void {
        $c = new FakeClient([$this->dd('1','a','1'),$this->dd('2','b','2'),$this->dd('3','c','3')]);
        $this->expectException(Modules_DondominioDns_BlockedException::class);
        try { (new S($c,new B($this->dir)))->sync('ej.es',[$this->rec('a','1')]); }
        finally { $this->assertSame([],$c->log); }
    }
    public function testForceBypassesBlock(): void {
        $c = new FakeClient([$this->dd('1','a','1'),$this->dd('2','b','2')]);
        (new S($c,new B($this->dir)))->sync('ej.es',[],true);
        $this->assertCount(2,$c->log);
    }
    public function testNsInDonDominioIsNeverDeleted(): void {
        $c = new FakeClient([$this->dd('1','a','1'),$this->dd('9','ej.es','ns1.x','NS')]);
        (new S($c,new B($this->dir)))->sync('ej.es',[$this->rec('a','1')]);
        $this->assertSame([],$c->log);
    }
    public function testBackupWrittenBeforeChangeAndRotated(): void {
        $b = new B($this->dir,2);
        foreach ([1,2,3] as $i) { $b->save('ej.es','dondominio',[['n'=>$i]]); usleep(1100); }
        $this->assertCount(2, glob($this->dir.'/ej.es/*.json'));
        $c = new FakeClient([$this->dd('1','a','1')]);
        (new S($c,$b))->sync('nuevo.es',[$this->rec('a','1'),$this->rec('b','2')]);
        $this->assertCount(1, glob($this->dir.'/nuevo.es/*.json'));
    }
}
