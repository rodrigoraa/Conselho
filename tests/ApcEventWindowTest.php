<?php declare(strict_types=1);

namespace Tests;

use Apc\Services\EventWindow;

final class ApcEventWindowTest extends ApcTestCase
{
    private array $event=['data'=>'2026-08-15','status'=>'ATIVO','disponibilizado_em'=>'2026-08-08 08:00:00'];

    public function testSevenDayBoundariesAreInclusive():void
    {
        self::assertSame('AGUARDANDO',(new EventWindow('2026-08-07'))->describe($this->event)['state']);self::assertTrue((new EventWindow('2026-08-08'))->describe($this->event)['is_open']);self::assertTrue((new EventWindow('2026-08-22'))->describe($this->event)['is_open']);self::assertSame('ENCERRADA',(new EventWindow('2026-08-23'))->describe($this->event)['state']);
    }

    public function testWindowReportsExactDatesAndCancellationOverridesPeriod():void
    {
        $window=(new EventWindow('2026-08-15'))->describe($this->event);self::assertSame('2026-08-08',$window['opens_on']);self::assertSame('2026-08-22',$window['closes_on']);self::assertSame('CANCELADA',(new EventWindow('2026-08-15'))->describe($this->event+['evento_status'=>'CANCELADO'])['state']);
    }

    public function testActiveEventOpensAutomaticallyInsideDateWindow():void
    {
        $event=$this->event;$event['disponibilizado_em']=null;$window=new EventWindow('2026-08-15');$description=$window->describe($event);self::assertSame('ABERTA',$description['state']);self::assertTrue($description['is_within_window']);self::assertTrue($description['is_open']);$window->assertOpen($event);
    }
}
