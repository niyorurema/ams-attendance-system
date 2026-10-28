<?php

use App\Services\LdapAuthService;
use App\Services\LdapEmployeeEmailSyncService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\AttendanceDatabaseTestTrait;

/** @internal */
final class LdapEmployeeEmailSyncServiceTest extends CIUnitTestCase
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

    public function testPreviewDoesNotWriteAndApplyUpdatesEmployeeAndLinkedAccount(): void
    {
        $accountId = $this->createAccount($this->tenantId, $this->employeId, null);
        $service = $this->service([
            $this->employeId => ['statut' => 'trouve', 'email' => 'employee@obr.gov.bi'],
        ]);

        $preview = $service->preview($this->tenantId);
        $this->assertSame(1, $preview['emails_employes_mis_a_jour']);
        $this->assertSame(1, $preview['emails_comptes_mis_a_jour']);
        $this->assertNull($this->attendanceDb->table('employes')->where('id', $this->employeId)->get()->getRowArray()['email']);
        $this->assertNull($this->attendanceDb->table('users')->where('id', $accountId)->get()->getRowArray()['email']);

        $result = $service->apply($this->tenantId);
        $this->assertSame(1, $result['emails_employes_mis_a_jour']);
        $this->assertSame(1, $result['emails_comptes_mis_a_jour']);
        $this->assertSame(
            'employee@obr.gov.bi',
            $this->attendanceDb->table('employes')->where('id', $this->employeId)->get()->getRowArray()['email']
        );
        $this->assertSame(
            'employee@obr.gov.bi',
            $this->attendanceDb->table('users')->where('id', $accountId)->get()->getRowArray()['email']
        );
    }

    public function testExistingAccountEmailCollisionDoesNotPreventEmployeeUpdate(): void
    {
        $linkedAccountId = $this->createAccount($this->tenantId, $this->employeId, null);
        $existingAccountId = $this->createAccount($this->tenantId, null, 'employee@obr.gov.bi');
        $service = $this->service([
            $this->employeId => ['statut' => 'trouve', 'email' => 'employee@obr.gov.bi'],
        ]);

        $result = $service->apply($this->tenantId);

        $this->assertSame(1, $result['emails_employes_mis_a_jour']);
        $this->assertSame(0, $result['emails_comptes_mis_a_jour']);
        $this->assertSame(1, $result['collisions']);
        $this->assertNull($this->attendanceDb->table('users')->where('id', $linkedAccountId)->get()->getRowArray()['email']);
        $this->assertSame(
            'employee@obr.gov.bi',
            $this->attendanceDb->table('users')->where('id', $existingAccountId)->get()->getRowArray()['email']
        );
    }

    /** @param array<int, array{statut:string,email:?string}> $matches */
    private function service(array $matches): LdapEmployeeEmailSyncService
    {
        $ldap = new class($matches) extends LdapAuthService {
            /** @param array<int, array{statut:string,email:?string}> $matches */
            public function __construct(private array $matches) {}

            public function rechercherEmailsEmployes(int $tenantId, array $employes): array
            {
                return array_intersect_key($this->matches, array_flip(array_column($employes, 'id')));
            }
        };

        return new LdapEmployeeEmailSyncService($this->attendanceDb, $ldap);
    }

    private function createAccount(int $tenantId, ?int $employeeId, ?string $email): int
    {
        $this->attendanceDb->table('users')->insert([
            'tenant_id' => $tenantId,
            'employe_id' => $employeeId,
            'email' => $email,
            'password_hash' => null,
            'role' => 'lecteur',
            'is_super_admin' => 0,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->attendanceDb->insertID();
    }
}
