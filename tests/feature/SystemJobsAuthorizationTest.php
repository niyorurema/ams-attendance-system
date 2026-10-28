<?php

namespace Tests\Feature;

use App\Controllers\Api\SystemJobsController;
use App\Services\SystemJobService;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Migrations;
use Config\Services;
use Tests\Support\AttendanceDatabaseTestTrait;

/** Vérifie que la consultation et la relance sont interdites hors super-admin. */
final class SystemJobsAuthorizationTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $migrations = new MigrationRunner(config(Migrations::class), 'tests');
        $migrations->setNamespace('App');
        if (! $migrations->latest()) {
            $this->fail('Impossible d’appliquer les migrations à la base de test.');
        }
        $this->setUpAttendanceDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownAttendanceDatabase();
        parent::tearDown();
    }

    public function testAdministrateurOrdinaireReçoit403SurLectureEtRelance(): void
    {
        $controller = $this->controller(false);

        $this->assertSame(403, $controller->index()->getStatusCode());
        $this->assertSame(403, $controller->retry(1)->getStatusCode());
    }

    public function testSuperAdminPeutConsulterLesTachesEtReçoitErreurLisiblePourRelanceInvalide(): void
    {
        $controller = $this->controller(true);
        $listResponse = $controller->index();
        $payload = json_decode($listResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $listResponse->getStatusCode());
        $this->assertArrayHasKey('runs', $payload['data']);
        $this->assertArrayHasKey('alerts', $payload['data']);

        $retryResponse = $controller->retry(99999);
        $retryPayload = json_decode($retryResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(422, $retryResponse->getStatusCode());
        $this->assertFalse($retryPayload['success']);
        $this->assertNotEmpty($retryPayload['message']);
    }

    private function controller(bool $superAdmin): SystemJobsController
    {
        $db = $this->attendanceDb;
        $controller = new class($db, $superAdmin) extends SystemJobsController {
            public function __construct(
                private readonly \CodeIgniter\Database\BaseConnection $testDb,
                private readonly bool $testSuperAdmin
            ) {
            }

            protected function claims(): ?\stdClass
            {
                return (object) [
                    'sub' => '7',
                    'tenant_id' => 1,
                    'role' => $this->testSuperAdmin ? 'admin' : 'admin',
                    'is_super_admin' => $this->testSuperAdmin,
                ];
            }

            protected function jobService(): SystemJobService
            {
                return new SystemJobService($this->testDb);
            }
        };
        $controller->initController(
            Services::incomingrequest(new App(), false),
            Services::response(new App(), false),
            Services::logger()
        );

        return $controller;
    }
}
