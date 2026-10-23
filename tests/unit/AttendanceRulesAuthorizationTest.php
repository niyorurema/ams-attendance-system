<?php

use App\Controllers\Api\BaseApiController;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AttendanceRulesAuthorizationTest extends CIUnitTestCase
{
    public function testAdminPeutActiverReglesNouvelles(): void
    {
        $controller = new class() extends BaseApiController {
            public ?stdClass $testClaims = null;

            protected function claims(): ?stdClass
            {
                return $this->testClaims;
            }

            public function peutGerer(): bool
            {
                return $this->canManage();
            }

            public function peutConsulterParametresPointage(): bool
            {
                return $this->canReadAttendanceSettings();
            }
        };
        $controller->testClaims = (object) [
            'role' => 'admin',
            'is_super_admin' => false,
        ];

        $this->assertTrue($controller->peutGerer());
    }

    public function testRHPeutActiverReglesNouvelles(): void
    {
        $controller = new class() extends BaseApiController {
            public ?stdClass $testClaims = null;

            protected function claims(): ?stdClass
            {
                return $this->testClaims;
            }

            public function peutGerer(): bool
            {
                return $this->canManage();
            }

            public function peutConsulterParametresPointage(): bool
            {
                return $this->canReadAttendanceSettings();
            }
        };
        $controller->testClaims = (object) [
            'role' => 'rh',
            'is_super_admin' => false,
        ];

        $this->assertTrue($controller->peutGerer());
    }

    public function testSuperviseurPeutConsulterMaisPasActiverReglesNouvelles(): void
    {
        $controller = new class() extends BaseApiController {
            public ?stdClass $testClaims = null;

            protected function claims(): ?stdClass
            {
                return $this->testClaims;
            }

            public function peutGerer(): bool
            {
                return $this->canManage();
            }

            public function peutConsulterParametresPointage(): bool
            {
                return $this->canReadAttendanceSettings();
            }
        };
        $controller->testClaims = (object) [
            'role' => 'superviseur',
            'is_super_admin' => false,
        ];

        $this->assertFalse($controller->peutGerer());
        $this->assertTrue($controller->peutConsulterParametresPointage());
    }
}
