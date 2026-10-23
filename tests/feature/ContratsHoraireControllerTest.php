<?php

namespace Tests\Feature;

use App\Controllers\Api\ContratsHoraireController;
use App\Services\ContratsHoraireService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Services;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class ContratsHoraireControllerTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    private IncomingRequest $controllerRequest;

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

    public function testSuperviseurPeutListerContrats(): void
    {
        $this->assertSame(200, $this->controller('superviseur')->index($this->employeId)->getStatusCode());
    }

    public function testSuperviseurNePeutPasCreerContrat(): void
    {
        $controller = $this->controller('superviseur');
        $this->setJsonInput();

        $this->assertSame(403, $controller->create($this->employeId)->getStatusCode());
    }

    public function testAdminPeutCreerContrat(): void
    {
        $this->assertSame(201, $this->creerAvecRole('admin'));
    }

    public function testRhPeutCreerContrat(): void
    {
        $this->assertSame(201, $this->creerAvecRole('rh'));
    }

    public function testMultiTenant404(): void
    {
        $autreTenantId = $this->creerTenant();
        $autreEmployeId = $this->creerEmploye($autreTenantId);
        $contrat = (new ContratsHoraireService($this->attendanceDb))->creer(
            $autreTenantId,
            $autreEmployeId,
            $this->donneesContrat()
        );

        $this->assertSame(
            404,
            $this->controller('admin')->update($this->employeId, (int) $contrat['id'])->getStatusCode()
        );
    }

    private function creerAvecRole(string $role): int
    {
        $controller = $this->controller($role);
        $this->setJsonInput();

        return $controller->create($this->employeId)->getStatusCode();
    }

    private function controller(string $role): ContratsHoraireController
    {
        $tenantId = $this->tenantId;
        $this->controllerRequest = Services::incomingrequest(new App(), false);
        $controller = new class($tenantId, $role) extends ContratsHoraireController {
            public function __construct(
                private readonly int $testTenantId,
                private readonly string $testRole
            ) {
            }

            protected function claims(): ?\stdClass
            {
                return (object) ['role' => $this->testRole, 'is_super_admin' => false];
            }

            protected function tenantId(?int $requestedTenantId = null): ?int
            {
                return $requestedTenantId ?? $this->testTenantId;
            }
        };

        $controller->initController(
            $this->controllerRequest,
            Services::response(new App(), false),
            Services::logger()
        );

        return $controller;
    }

    private function setJsonInput(): void
    {
        $this->controllerRequest->setBody(json_encode($this->donneesContrat(), JSON_THROW_ON_ERROR));
        $this->controllerRequest->setHeader('Content-Type', 'application/json');
    }

    /** @return array<string, mixed> */
    private function donneesContrat(): array
    {
        return [
            'mode_travail' => 'TEMPS_PLEIN',
            'heure_debut' => '07:30',
            'heure_fin' => '17:30',
            'jours_travail' => [1, 2, 3, 4, 5],
            'date_debut' => '2026-10-19',
        ];
    }
}
