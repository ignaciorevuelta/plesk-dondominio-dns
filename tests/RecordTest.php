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
    public function testToPleskRoundTrips(): void {
        $cases = [
            ['host'=>'ej.es.','type'=>'MX','opt'=>'10','value'=>'mail.ej.es.'],
            ['host'=>'_imaps._tcp.ej.es.','type'=>'SRV','opt'=>'0 0 993','value'=>'ej.es.'],
            ['host'=>'www.ej.es.','type'=>'CNAME','opt'=>'','value'=>'ej.es.'],
            ['host'=>'ej.es.','type'=>'TXT','opt'=>'','value'=>'v=spf1 -all'],
            ['host'=>'ej.es.','type'=>'CAA','opt'=>'0 issue','value'=>'letsencrypt.org'],
        ];
        foreach ($cases as $c) {
            $rec = R::fromPlesk((object)$c);
            $back = R::toPlesk($rec, 'ej.es');
            $relative = $c['host'] === 'ej.es.' ? '' : substr($c['host'], 0, -strlen('.ej.es.'));
            $this->assertSame($relative, $back['host'], $c['type']);
            $this->assertSame($c['opt'], $back['opt'], $c['type']);
            $this->assertSame(R::key($rec), R::key(R::fromPlesk((object)['host'=>$c['host'],'type'=>$back['type'],'opt'=>$back['opt'],'value'=>$back['value']])), $c['type']);
        }
    }
    public function testToPleskFromDonDominioMx(): void {
        $rec = R::fromDonDominio(['entityID'=>'1','name'=>'ej.es','type'=>'MX','ttl'=>'-','priority'=>'0','value'=>'ej-es.mail.protection.outlook.com']);
        $this->assertSame(['host'=>'','type'=>'MX','opt'=>'0','value'=>'ej-es.mail.protection.outlook.com.'], R::toPlesk($rec, 'ej.es'));
    }
    public function testZoneApexesDropsSubdomainsOfListedDomains(): void {
        $this->assertSame(['aossa.es','uttes.es','solo.sub.ej.es'],
            R::zoneApexes(['aossa.es','sentya.aossa.es','uttes.es','a.b.uttes.es','solo.sub.ej.es']));
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
    public function testSrvKeyEqualAcrossSides(): void {
        $p = R::fromPlesk((object)['host'=>'_sip._tcp.ej.es.','type'=>'SRV','opt'=>'0 5 5060','value'=>'sip.ej.es.']);
        $d = R::fromDonDominio(['entityID'=>'S','name'=>'_sip._tcp.ej.es','type'=>'SRV','ttl'=>'-','priority'=>'0','value'=>'5 5060 Sip.ej.es.']);
        $this->assertSame(R::key($p), R::key($d));
    }
    public function testCaaKeyEqualAcrossSides(): void {
        $p = R::fromPlesk((object)['host'=>'ej.es.','type'=>'CAA','opt'=>'0 issue','value'=>'"letsencrypt.org"']);
        $d = R::fromDonDominio(['entityID'=>'C','name'=>'ej.es','type'=>'CAA','ttl'=>'-','priority'=>'-','value'=>'0 issue "letsencrypt.org"']);
        $this->assertSame(R::key($p), R::key($d));
    }
    public function testSrvWithBadOptIsNull(): void {
        $this->assertNull(R::fromPlesk((object)['host'=>'s.ej.es.','type'=>'SRV','opt'=>'','value'=>'x.']));
        $this->assertNull(R::fromPlesk((object)['host'=>'s.ej.es.','type'=>'SRV','opt'=>'0 5','value'=>'x.']));
    }
}
