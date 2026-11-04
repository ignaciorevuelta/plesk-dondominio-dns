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
    public function testSyncableKeepsDelegationsDropsApexNsAndGlue(): void {
        $recs = [
            ['name'=>'ej.es','type'=>'NS','priority'=>'-','value'=>'ns1.ej.es','id'=>null],
            ['name'=>'ns1.ej.es','type'=>'A','priority'=>'-','value'=>'192.0.2.1','id'=>null],
            ['name'=>'sub.ej.es','type'=>'NS','priority'=>'-','value'=>'ns.other.net','id'=>null],
            ['name'=>'www.ej.es','type'=>'A','priority'=>'-','value'=>'192.0.2.2','id'=>null],
            ['name'=>'sub.ej.es','type'=>'DS','priority'=>'-','value'=>'1 8 2 ABC','id'=>null],
            ['name'=>'ej.es','type'=>'SOA','priority'=>'-','value'=>'x','id'=>null],
        ];
        $this->assertSame(['sub.ej.es NS','www.ej.es A'], array_map(fn($r)=>$r['name'].' '.$r['type'], R::syncable($recs, 'ej.es')));
        $this->assertSame(['sub.ej.es DS'], array_map(fn($r)=>$r['name'].' '.$r['type'], R::unsyncable($recs, 'ej.es')));
    }
    public function testToPleskNsDelegation(): void {
        $rec = R::fromDonDominio(['entityID'=>'N','name'=>'sub.ej.es','type'=>'NS','ttl'=>'-','priority'=>'-','value'=>'ns.other.net']);
        $this->assertSame(['host'=>'sub','type'=>'NS','opt'=>'','value'=>'ns.other.net.'], R::toPlesk($rec, 'ej.es'));
    }
    public function testTxtKeepsCase(): void {
        $p = R::fromPlesk((object)['host'=>'ej.es.','type'=>'TXT','opt'=>'','value'=>'v=spf1 Include:X -all']);
        $this->assertSame('v=spf1 Include:X -all', $p['value']);
    }
    public function testNsIsNormalizedSoaAndUnknownAreSkipped(): void {
        $p = R::fromPlesk((object)['host'=>'Sub.ej.es.','type'=>'NS','opt'=>'','value'=>'NS1.Other.net.']);
        $d = R::fromDonDominio(['entityID'=>'N','name'=>'sub.ej.es','type'=>'NS','ttl'=>'-','priority'=>'-','value'=>'ns1.other.net']);
        $this->assertSame(R::key($p), R::key($d));
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
