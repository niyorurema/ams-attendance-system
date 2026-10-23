<?php

namespace Tests\Feature;

use App\Models\TenantModel;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class TenantConfigUpdateTest extends CIUnitTestCase
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

    public function testUnTenantPeutEnregistrerSaConfigurationEnConservantSonSlug(): void
    {
        $model = new TenantModel($this->attendanceDb);
        $tenant = $model->find($this->tenantId);
        $this->assertIsArray($tenant);

        $updated = $model->update($this->tenantId, [
            'id' => $this->tenantId,
            'name' => 'Configuration modifiée',
            'slug' => $tenant['slug'],
            'timezone' => 'Africa/Bujumbura',
            'is_active' => 1,
        ]);

        $this->assertTrue($updated, implode(' ', $model->errors()));
        $this->assertSame($tenant['slug'], $model->find($this->tenantId)['slug']);
    }
}
