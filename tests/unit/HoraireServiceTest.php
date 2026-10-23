<?php

use App\Services\HoraireService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class HoraireServiceTest extends CIUnitTestCase
{
    public function testAccepteDesPlagesNonChevauchantes(): void
    {
        $service = new HoraireService();

        $this->assertTrue($service->validerPlagesHoraires([
            ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:15'],
            ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30'],
        ]));
    }

    public function testRefuseLesPlagesChevauchantesOuInvalides(): void
    {
        $service = new HoraireService();

        $this->assertFalse($service->validerPlagesHoraires([
            ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:30'],
            ['type' => 'CHECK_OUT', 'debut' => '08:00', 'fin' => '17:30'],
        ]));
        $this->assertFalse($service->validerPlagesHoraires([
            ['type' => 'CHECK_IN', 'debut' => '08:30', 'fin' => '08:00'],
        ]));
    }
}
