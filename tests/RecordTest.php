<?php
use PHPUnit\Framework\TestCase;
use Modules_DondominioDns_Record as R;

final class RecordTest extends TestCase {
    public function testSameRecordFromBothSidesHasSameKey(): void {
        $p = R::fromPlesk((object)['host'=>'WWW.Ej.es.','type'=>'cname','opt'=>'','value'=>'Dest.Ej.es.']);
        $d = R::fromDonDominio(['entityID'=>'X','name'=>'www.ej.es','type'=>'CNAME','ttl'=>'-','priority'=>'-','value'=>'dest.ej.es']);
        $this->assertSame(R::key($p), R::key($d));
        $this->assertSame('X', $d['id']);
    }
    public function testMxPriorityComesFromOpt(): void {
        $p = R::fromPlesk((object)['host'=>'ej.es.','type'=>'MX','opt'=>'10','value'=>'mail.ej.es.']);
        $d = R::fromDonDominio(['entityID'=>'Y','name'=>'ej.es','type'=>'MX','ttl'=>'-','priority'=>'10','value'=>'mail.ej.es']);
        $this->assertSame('10', $p['priority']);
        $this->assertSame(R::key($p), R::key($d));
    }
    public function testSrvMapsOptToPriorityAndValue(): void {
        $p = R::fromPlesk((object)['host'=>'_sip._tcp.ej.es.','type'=>'SRV','opt'=>'0 5 5060','value'=>'sip.ej.es.']);
        $this->assertSame('0', $p['priority']);
        $this->assertSame('5 5060 sip.ej.es', $p['value']);
    }
    public function testTxtKeepsCase(): void {
        $p = R::fromPlesk((object)['host'=>'ej.es.','type'=>'TXT','opt'=>'','value'=>'v=spf1 Include:X -all']);
        $this->assertSame('v=spf1 Include:X -all', $p['value']);
    }
    public function testNsSoaAndUnknownAreSkipped(): void {
        $this->assertNull(R::fromPlesk((object)['host'=>'ej.es.','type'=>'NS','opt'=>'','value'=>'ns1.x.']));
        $this->assertNull(R::fromDonDominio(['entityID'=>'Z','name'=>'ej.es','type'=>'SOA','ttl'=>'-','priority'=>'-','value'=>'x']));
        $this->assertNull(R::fromPlesk((object)['host'=>'ej.es.','type'=>'PTR','opt'=>'','value'=>'x.']));
    }
}
