<?php

use App\Services\ContratsHoraireService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class ContratsHoraireServiceTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttendanceDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownAttendanceDatabase();
        parent::tearDown();
    }

    public function testCreerContratTempsPlein(): void
    {
        $contrat = $this->service()->creer($this->tenantId, $this->employeId, $this->donneesContrat());

        $this->assertSame($this->tenantId, (int) $contrat['tenant_id']);
        $this->assertSame('TEMPS_PLEIN', $contrat['mode_travail']);
        $this->assertSame('07:30:00', $contrat['heure_debut']);
        $this->assertSame($contrat['id'], $this->service()->trouver((int) $contrat['id'])['id']);
    }

    public function testCreerContratMitempsApresMidi(): void
    {
        $contrat = $this->service()->creer($this->tenantId, $this->employeId, $this->donneesContrat([
            'mode_travail' => 'MI_TEMPS_APRES_MIDI',
            'heure_debut' => '13:00',
            'heure_fin' => '17:00',
        ]));

        $this->assertSame('MI_TEMPS_APRES_MIDI', $contrat['mode_travail']);
        $this->assertSame('13:00:00', $contrat['heure_debut']);
    }

    public function testRefuserChevauchementStrict(): void
    {
        $this->service()->creer($this->tenantId, $this->employeId, $this->donneesContrat());

        $this->expectException(DomainException::class);
        $this->service()->creer($this->tenantId, $this->employeId, $this->donneesContrat([
            'date_debut' => '2026-10-20',
        ]));
    }

    public function testCloturerAncienContratSiDemande(): void
    {
        $service = $this->service();
        $ancien = $service->creer($this->tenantId, $this->employeId, $this->donneesContrat());
        $nouveau = $service->creer($this->tenantId, $this->employeId, $this->donneesContrat([
            'date_debut' => '2026-10-20',
            'cloturer_contrat_existant' => true,
        ]));

        $this->assertSame('2026-10-19', $service->trouver((int) $ancien['id'])['date_fin']);
        $this->assertSame('2026-10-20', $nouveau['date_debut']);
    }

    public function testGetContratActifParDate(): void
    {
        $service = $this->service();
        $contrat = $service->creer($this->tenantId, $this->employeId, $this->donneesContrat([
            'date_fin' => '2026-10-25',
        ]));

        $this->assertSame((int) $contrat['id'], (int) $service->getContratActif($this->employeId, '2026-10-22')['id']);
        $this->assertNull($service->getContratActif($this->employeId, '2026-10-26'));
    }

    public function testGetContratActifAucunContrat(): void
    {
        $this->assertNull($this->service()->getContratActif($this->employeId, '2026-10-19'));
    }

    public function testSupprimerContratSoftDelete(): void
    {
        $service = $this->service();
        $contrat = $service->creer($this->tenantId, $this->employeId, $this->donneesContrat());

        $this->assertTrue($service->supprimer((int) $contrat['id']));
        $this->assertNotNull($this->attendanceDb->table('employe_contrats_horaire')
            ->where('id', (int) $contrat['id'])->get()->getRowArray()['deleted_at']);
        $this->assertNull($service->trouver((int) $contrat['id']));
    }

    public function testModifierContrat(): void
    {
        $service = $this->service();
        $contrat = $service->creer($this->tenantId, $this->employeId, $this->donneesContrat());
        $modifie = $service->modifier((int) $contrat['id'], ['notes' => 'Horaire adapté']);

        $this->assertSame('Horaire adapté', $modifie['notes']);
    }

    public function testJoursTravailJson(): void
    {
        $contrat = $this->service()->creer($this->tenantId, $this->employeId, $this->donneesContrat([
            'jours_travail' => [1, 3, 5, 5],
        ]));

        $this->assertSame([1, 3, 5], json_decode($contrat['jours_travail'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTrouverContrat(): void
    {
        $service = $this->service();
        $contrat = $service->creer($this->tenantId, $this->employeId, $this->donneesContrat());

        $this->assertSame((int) $contrat['id'], (int) $service->trouver((int) $contrat['id'])['id']);
        $this->assertNull($service->trouver(2147483647));
    }

    private function service(): ContratsHoraireService
    {
        return new ContratsHoraireService($this->attendanceDb);
    }

    /** @return array<string, mixed> */
    private function donneesContrat(array $modifications = []): array
    {
        return array_replace([
            'mode_travail' => 'TEMPS_PLEIN',
            'heure_debut' => '07:30',
            'heure_fin' => '17:30',
            'jours_travail' => [1, 2, 3, 4, 5],
            'date_debut' => '2026-10-19',
        ], $modifications);
    }
}
