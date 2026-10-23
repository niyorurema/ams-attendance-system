<?php

use App\Services\UniteOrganisationnelleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class UniteOrganisationnelleServiceTest extends CIUnitTestCase
{
    public function testRetourneTousLesDescendantsSansInclureLaRacine(): void
    {
        $service = $this->creerServiceTest();

        $descendantes = $service->getUnitesDescendantes(1);

        $this->assertSame([2, 3, 4], array_column($descendantes, 'id'));
    }

    public function testRetourneLesEmployesDeLaBrancheOuDeLUniteSeule(): void
    {
        $service = $this->creerServiceTest();

        $this->assertSame([101, 102, 103, 104], array_column($service->getEmployesDeUnite(1), 'id'));
        $this->assertSame([102], array_column($service->getEmployesDeUnite(2, false), 'id'));
    }

    public function testConstruitUnArbreAvecLesEnfantsDeChaqueNiveau(): void
    {
        $service = $this->creerServiceTest();

        $arbre = $service->getArbreHierarchique(10);

        $this->assertCount(1, $arbre);
        $this->assertSame(1, $arbre[0]['id']);
        $this->assertSame([2, 3], array_column($arbre[0]['enfants'], 'id'));
        $this->assertSame([4], array_column($arbre[0]['enfants'][1]['enfants'], 'id'));
    }

    private function creerServiceTest(): UniteOrganisationnelleService
    {
        return new class extends UniteOrganisationnelleService {
            private array $unites = [
                ['id' => 1, 'tenant_id' => 10, 'parent_id' => null, 'nom' => 'Direction', 'actif' => 1],
                ['id' => 2, 'tenant_id' => 10, 'parent_id' => 1, 'nom' => 'Finances', 'actif' => 1],
                ['id' => 3, 'tenant_id' => 10, 'parent_id' => 1, 'nom' => 'Opérations', 'actif' => 1],
                ['id' => 4, 'tenant_id' => 10, 'parent_id' => 3, 'nom' => 'Équipe terrain', 'actif' => 1],
                ['id' => 5, 'tenant_id' => 20, 'parent_id' => 1, 'nom' => 'Autre tenant', 'actif' => 1],
            ];

            private array $employes = [
                ['id' => 101, 'tenant_id' => 10, 'unite_id' => 1, 'actif' => 1],
                ['id' => 102, 'tenant_id' => 10, 'unite_id' => 2, 'actif' => 1],
                ['id' => 103, 'tenant_id' => 10, 'unite_id' => 3, 'actif' => 1],
                ['id' => 104, 'tenant_id' => 10, 'unite_id' => 4, 'actif' => 1],
                ['id' => 105, 'tenant_id' => 10, 'unite_id' => 4, 'actif' => 0],
            ];

            public function __construct()
            {
            }

            protected function trouverUniteActive(int $uniteId): ?array
            {
                foreach ($this->unites as $unite) {
                    if ($unite['id'] === $uniteId && $unite['actif'] === 1) {
                        return $unite;
                    }
                }

                return null;
            }

            protected function trouverEnfantsActifs(int $parentId, int $tenantId): array
            {
                return array_values(array_filter($this->unites, static fn (array $unite): bool =>
                    $unite['tenant_id'] === $tenantId
                    && $unite['parent_id'] === $parentId
                    && $unite['actif'] === 1
                ));
            }

            protected function trouverEmployesActifs(int $tenantId, array $uniteIds): array
            {
                return array_values(array_filter($this->employes, static fn (array $employe): bool =>
                    $employe['tenant_id'] === $tenantId
                    && in_array($employe['unite_id'], $uniteIds, true)
                    && $employe['actif'] === 1
                ));
            }

            protected function trouverUnitesActivesDuTenant(int $tenantId): array
            {
                return array_values(array_filter($this->unites, static fn (array $unite): bool =>
                    $unite['tenant_id'] === $tenantId && $unite['actif'] === 1
                ));
            }
        };
    }
}
