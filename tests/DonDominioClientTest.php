<?php
use PHPUnit\Framework\TestCase;
use Modules_DondominioDns_DonDominioClient as C;
use Modules_DondominioDns_ApiException as E;

final class DonDominioClientTest extends TestCase {
    public function testListRecordsPaginates(): void {
        $calls = [];
        $c = new C('u','p', function($url,$post) use (&$calls) {
            $calls[] = [$url,$post];
            $page = (int)$post['page'];
            return json_encode(['success'=>true,'responseData'=>['queryInfo'=>['total'=>1001],
                'dns'=> $page === 1 ? array_fill(0,1000,['entityID'=>'a']) : [['entityID'=>'b']]]]);
        });
        $this->assertCount(1001, $c->listRecords('ej.es'));
        $this->assertStringEndsWith('/service/dnslist/', $calls[0][0]);
        $this->assertSame('ej.es', $calls[0][1]['serviceName']);
    }
    public function testErrorThrowsWithoutCredentials(): void {
        $c = new C('secretuser','secretpass', fn() => json_encode(['success'=>false,'errorCode'=>1003,'errorCodeMsg'=>'Account not exists']));
        try { $c->listServices(); $this->fail(); }
        catch (E $e) {
            $this->assertStringContainsString('1003', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }
    public function testCreateOmitsDefaultPriority(): void {
        $sent = null;
        $c = new C('u','p', function($url,$post) use (&$sent) { $sent = $post; return '{"success":true,"responseData":[]}'; });
        $c->create('ej.es', ['name'=>'a.ej.es','type'=>'A','priority'=>'-','value'=>'1.1.1.1','id'=>null]);
        $this->assertArrayNotHasKey('priority', $sent);
        $this->assertSame('a.ej.es', $sent['name']);
    }

    private function reading(array $data): C {
        // Solo la página 1 trae datos; las siguientes vienen vacías (lista que no llega al total).
        return new C('u','p', function($url,$post) use ($data) {
            if ((int)$post['page'] > 1) { foreach (['dns','services'] as $k) { if (isset($data[$k])) $data[$k] = []; } }
            return json_encode(['success'=>true,'responseData'=>$data]);
        });
    }
    public function testListRecordsRejectsMissingDnsKey(): void {
        $this->expectException(E::class);
        $this->reading(['queryInfo'=>['total'=>0]])->listRecords('ej.es');
    }
    public function testListRecordsRejectsNonArrayResponseData(): void {
        $c = new C('u','p', fn() => '{"success":true,"responseData":"x"}');
        $this->expectException(E::class);
        $c->listRecords('ej.es');
    }
    public function testListRecordsRejectsShortList(): void {
        $this->expectException(E::class);
        $this->reading(['queryInfo'=>['total'=>5],'dns'=>[['entityID'=>'a']]])->listRecords('ej.es');
    }
    public function testListRecordsAcceptsEmptyZone(): void {
        $this->assertSame([], $this->reading(['queryInfo'=>['total'=>0],'dns'=>[]])->listRecords('ej.es'));
    }
    public function testListServicesRejectsMissingServicesKey(): void {
        $this->expectException(E::class);
        $this->reading(['queryInfo'=>['total'=>0]])->listServices();
    }
    public function testListServicesRejectsShortList(): void {
        $this->expectException(E::class);
        $this->reading(['queryInfo'=>['total'=>3],'services'=>[['name'=>'a.es']]])->listServices();
    }
    public function testWritesAcceptNonArrayResponseData(): void {
        $c = new C('u','p', fn() => '{"success":true,"responseData":"ok"}');
        $c->delete('ej.es','1');
        $this->assertTrue(true);
    }
}
