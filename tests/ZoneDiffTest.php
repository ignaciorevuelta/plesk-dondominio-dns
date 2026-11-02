<?php
use PHPUnit\Framework\TestCase;
use Modules_DondominioDns_ZoneDiff as D;

final class ZoneDiffTest extends TestCase {
    private function r($n,$t,$v,$id=null,$p='-'){ return ['name'=>$n,'type'=>$t,'priority'=>$p,'value'=>$v,'id'=>$id]; }

    public function testIdenticalZonesGiveEmptyPlan(): void {
        $p = D::plan([$this->r('a','A','1')], [$this->r('a','A','1','X')]);
        $this->assertSame(['create'=>[],'update'=>[],'delete'=>[]], $p);
    }
    public function testSingleValueChangeIsUpdateNotDeleteCreate(): void {
        $p = D::plan([$this->r('w','CNAME','new')], [$this->r('w','CNAME','old','X')]);
        $this->assertSame('X', $p['update'][0]['id']);
        $this->assertSame('new', $p['update'][0]['record']['value']);
        $this->assertSame([], $p['create']);
        $this->assertSame([], $p['delete']);
    }
    public function testRecordOnlyInCurrentIsDeleted(): void {
        $p = D::plan([], [$this->r('auto','CNAME','outlook','X')]);
        $this->assertSame('X', $p['delete'][0]['id']);
    }
    public function testMultipleSameNameTypeRecordsDiffIndividually(): void {
        $p = D::plan(
            [$this->r('e','TXT','a'), $this->r('e','TXT','c')],
            [$this->r('e','TXT','a','1'), $this->r('e','TXT','b','2'), $this->r('e','TXT','d','3')]
        );
        $this->assertSame([], $p['create']);
        $this->assertCount(1, $p['update']);
        $this->assertCount(1, $p['delete']);
    }
    public function testDuplicatesInCurrentAreRemoved(): void {
        $p = D::plan([$this->r('a','A','1')], [$this->r('a','A','1','X'), $this->r('a','A','1','Y')]);
        $this->assertSame('Y', $p['delete'][0]['id']);
    }
}
