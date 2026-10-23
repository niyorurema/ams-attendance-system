<?php

use App\Services\PointageEmployeResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PointageEmployeResolverTest extends CIUnitTestCase
{
    public function testMatriculeIsPreferredWhenBadgeBelongsToAnotherEmployee(): void
    {
        $employeeByMatricule = ['11514' => ['id' => 9, 'matricule' => '11514']];
        $employeeByBadge = ['45779' => ['id' => 2, 'matricule' => '1022']];

        $employee = (new PointageEmployeResolver())->resoudre(
            '11514',
            45779,
            $employeeByMatricule,
            $employeeByBadge
        );

        $this->assertSame(9, $employee['id']);
    }

    public function testFallsBackToBadgeWhenMatriculeDoesNotMatch(): void
    {
        $employeeByBadge = ['45779' => ['id' => 2, 'matricule' => '1022']];

        $employee = (new PointageEmployeResolver())->resoudre(
            'matricule-inconnu',
            45779,
            [],
            $employeeByBadge
        );

        $this->assertSame(2, $employee['id']);
    }

    public function testReturnsNullWhenNeitherIdentifierMatches(): void
    {
        $employee = (new PointageEmployeResolver())->resoudre('11514', 45779, [], []);

        $this->assertNull($employee);
    }
}
