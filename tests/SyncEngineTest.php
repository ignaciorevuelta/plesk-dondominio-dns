<?php
use PHPUnit\Framework\TestCase;
use Modules_DondominioDns_SyncEngine as S;
use Modules_DondominioDns_Backups as B;

final class FakeClient {
    public array $log = [];
    public ?string $failOn = null;
    public function __construct(public array $records) {}
    private function op($o){ if ($o === $this->failOn) throw new Modules_DondominioDns_ApiException('fallo '.$o); $this->log[]=$o; }
    public function listRecords($s){ return $this->records; }
    public function create($s,$r){ $this->op('create:'.$r['name']); }
    public function update($s,$id,$r){ $this->op("update:$id"); }
    public function delete($s,$id){ $this->op("delete:$id"); }
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
        foreach ([1,2,3] as $i) { $b->save('ej.es','dondominio',[['n'=>$i]]); }
        $files = glob($this->dir.'/ej.es/*.json'); sort($files);
        $this->assertCount(2, $files);
        $this->assertSame([['n'=>2]], json_decode(file_get_contents($files[0]), true));
        $this->assertSame([['n'=>3]], json_decode(file_get_contents($files[1]), true));
        $c = new FakeClient([$this->dd('1','a','1')]);
        (new S($c,$b))->sync('nuevo.es',[$this->rec('a','1'),$this->rec('b','2')]);
        $this->assertCount(1, glob($this->dir.'/nuevo.es/*.json'));
    }
    public function testUnencodableBackupThrowsAndKeepsOldOnes(): void {
        $b = new B($this->dir,1);
        $b->save('ej.es','dondominio',[['n'=>1]]);
        try { $b->save('ej.es','dondominio',[['n'=>"\xff"]]); $this->fail(); } catch (JsonException $e) {}
        $files = glob($this->dir.'/ej.es/*.json');
        $this->assertCount(1,$files);
        $this->assertSame([['n'=>1]], json_decode(file_get_contents($files[0]), true));
    }
    public function testUnsupportedTypeInDesiredIsIgnored(): void {
        $c = new FakeClient([$this->dd('1','a','1')]);
        $ns = ['name'=>'ej.es','type'=>'NS','priority'=>'-','value'=>'ns1.plesk','id'=>null];
        (new S($c,new B($this->dir)))->sync('ej.es',[$this->rec('a','1'),$ns]);
        $this->assertSame([],$c->log);
    }
    public function testSubstitutionsCountTowardThreshold(): void {
        $c = new FakeClient([$this->dd('1','a','1'),$this->dd('2','b','2'),$this->dd('3','c','3'),$this->dd('4','d','4')]);
        $this->expectException(Modules_DondominioDns_BlockedException::class);
        try { (new S($c,new B($this->dir)))->sync('ej.es',[$this->rec('a','9'),$this->rec('b','9'),$this->rec('c','9'),$this->rec('d','9')]); }
        finally { $this->assertSame([],$c->log); }
    }
    public function testPartialFailureReportsAppliedOps(): void {
        $c = new FakeClient([$this->dd('1','a','1'),$this->dd('2','b','2'),$this->dd('3','c','3'),$this->dd('4','d','4')]);
        $c->failOn = 'delete:4';
        try {
            (new S($c,new B($this->dir)))->sync('ej.es',[$this->rec('a','1'),$this->rec('b','2'),$this->rec('c','3'),$this->rec('z','9')]);
            $this->fail('debía lanzar');
        } catch (Modules_DondominioDns_PartialSyncException $e) {
            $this->assertSame(['create:z'],$e->getApplied());
            $this->assertInstanceOf(Modules_DondominioDns_ApiException::class,$e->getPrevious());
        }
    }
}
