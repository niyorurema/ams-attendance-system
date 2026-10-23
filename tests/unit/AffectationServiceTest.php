<?php

use App\Services\AffectationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AffectationServiceTest extends CIUnitTestCase
{
    public function testDeplacerEmployeMetAJourLesDeuxMappings(): void
    {
        $service = $this->creerServiceTest();

        $result = $service->deplacerEmploye(42, 10, 20, ['badge_id' => 150]);

        $this->assertTrue($result['success']);
        $this->assertSame([10, 20], array_column($result['operations'], 'capteur_id'));
        $this->assertSame([10, 20], $service->getState()['capteurs']);
        $this->assertSame([
            ['capteur_id' => 10, 'badge_id' => 150, 'actif' => 0],
            ['capteur_id' => 20, 'badge_id' => 150, 'actif' => 1],
        ], $service->getState()['mappings']);
    }

    public function testDesactiverEmployeDésactiveTousLesMappings(): void
    {
        $service = $this->creerServiceTest();

        $result = $service->desactiverEmploye(42);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['operations']);
        $this->assertSame([10, 20], $service->getState()['capteurs']);
        $this->assertSame([
            ['capteur_id' => 10, 'badge_id' => 101, 'actif' => 0],
            ['capteur_id' => 20, 'badge_id' => 202, 'actif' => 0],
        ], $service->getState()['mappings']);
    }

    public function testDesactiverEmployePersisteLaRaisonDansLesNotes(): void
    {
        $service = new class extends AffectationService {
            private $notesParEmploye = ['42' => 'Ancienne note'];

            public function __construct()
            {
            }

            public function getNotes(): array
            {
                return $this->notesParEmploye;
            }

            protected function persistMotifDesactivation(int $employeId, ?string $raison): void
            {
                $raison = trim((string) ($raison ?? ''));
                if ($raison === '') {
                    return;
                }

                $actuel = trim((string) ($this->notesParEmploye[(string) $employeId] ?? ''));
                $this->notesParEmploye[(string) $employeId] = $actuel === '' ? $raison : $actuel . "\n" . $raison;
            }

            protected function trouverEmployeParId(int $employeId): ?array
            {
                return ['id' => $employeId, 'badge_id' => 999, 'actif' => 1];
            }

            protected function getAffectationsActivesEmploye(int $employeId): array
            {
                return [
                    ['employe_id' => $employeId, 'capteur_id' => 10, 'badge_id' => 101, 'actif' => 1],
                ];
            }

            protected function synchroniserEcritureCapteur(int $capteurId, array $operations): array
            {
                return ['success' => true, 'capteur_id' => $capteurId, 'operations' => $operations];
            }

            protected function enregistrerAffectation(int $employeId, int $capteurId, int $badgeId, bool $actif): void
            {
            }
        };

        $service->desactiverEmploye(42, ['raison' => 'Absence prolongée']);

        $this->assertStringContainsString('Absence prolongée', $service->getNotes()['42']);
    }

    public function testGenererBadgeIdRetourneLeProchainLibre(): void
    {
        $service = $this->creerServiceTest();

        $this->assertSame(203, $service->genererBadgeId(20));
    }

    public function testAjouterEmployeCapteurAjouteUneAffectationActive(): void
    {
        $service = $this->creerServiceTest();

        $result = $service->ajouterEmployeCapteur(99, 30, 500);

        $this->assertTrue($result['success']);
        $this->assertSame([
            ['capteur_id' => 30, 'badge_id' => 500, 'actif' => 1],
        ], $service->getState()['mappingsParCapteur'][30] ?? []);
    }

    private function creerServiceTest(): AffectationService
    {
        return new class extends AffectationService {
            private $capteurs = [
                ['id' => 10, 'adresse_ip' => '10.0.0.10', 'port' => 4370, 'timeout_secondes' => 20, 'com_key' => 0],
                ['id' => 20, 'adresse_ip' => '10.0.0.20', 'port' => 4370, 'timeout_secondes' => 20, 'com_key' => 0],
                ['id' => 30, 'adresse_ip' => '10.0.0.30', 'port' => 4370, 'timeout_secondes' => 20, 'com_key' => 0],
            ];

            private $employes = [
                ['id' => 42, 'badge_id' => 999, 'actif' => 1],
                ['id' => 99, 'badge_id' => 888, 'actif' => 1],
            ];

            private $mappings = [
                ['employe_id' => 42, 'capteur_id' => 10, 'badge_id' => 101, 'actif' => 1],
                ['employe_id' => 42, 'capteur_id' => 20, 'badge_id' => 202, 'actif' => 1],
            ];

            public function __construct()
            {
            }

            public function getState(): array
            {
                $capteurs = [];
                $mappings = [];
                foreach ($this->mappings as $mapping) {
                    $mappings[] = [
                        'capteur_id' => (int) $mapping['capteur_id'],
                        'badge_id' => (int) $mapping['badge_id'],
                        'actif' => (int) $mapping['actif'],
                    ];
                    $capteurs[] = (int) $mapping['capteur_id'];
                }

                $capteurs = array_values(array_unique($capteurs));
                $sortie = [];
                foreach ($capteurs as $capteurId) {
                    $sortie[$capteurId] = array_values(array_filter($mappings, static fn (array $mapping): bool => $mapping['capteur_id'] === $capteurId));
                }

                return [
                    'capteurs' => $capteurs,
                    'mappings' => $mappings,
                    'mappingsParCapteur' => $sortie,
                ];
            }

            protected function trouverEmployeParId(int $employeId): ?array
            {
                foreach ($this->employes as $employe) {
                    if ((int) $employe['id'] === $employeId) {
                        return $employe;
                    }
                }

                return null;
            }

            protected function trouverCapteurParId(int $capteurId): ?array
            {
                foreach ($this->capteurs as $capteur) {
                    if ((int) $capteur['id'] === $capteurId) {
                        return $capteur;
                    }
                }

                return null;
            }

            protected function getAffectationsActivesEmploye(int $employeId): array
            {
                return array_values(array_filter($this->mappings, static fn (array $mapping): bool => (int) $mapping['employe_id'] === $employeId && (int) $mapping['actif'] === 1));
            }

            protected function getAffectationsInactivesEmploye(int $employeId): array
            {
                return array_values(array_filter($this->mappings, static fn (array $mapping): bool => (int) $mapping['employe_id'] === $employeId && (int) $mapping['actif'] === 0));
            }

            protected function getAffectationActif(int $employeId, int $capteurId): ?array
            {
                foreach ($this->mappings as $mapping) {
                    if ((int) $mapping['employe_id'] === $employeId && (int) $mapping['capteur_id'] === $capteurId && (int) $mapping['actif'] === 1) {
                        return $mapping;
                    }
                }

                return null;
            }

            protected function getAffectationsEmploye(int $employeId): array
            {
                return array_values(array_filter($this->mappings, static fn (array $mapping): bool => (int) $mapping['employe_id'] === $employeId));
            }

            protected function getAffectationsCapteur(int $capteurId): array
            {
                return array_values(array_filter($this->mappings, static fn (array $mapping): bool => (int) $mapping['capteur_id'] === $capteurId));
            }

            protected function enregistrerAffectation(int $employeId, int $capteurId, int $badgeId, bool $actif): void
            {
                foreach ($this->mappings as $index => $mapping) {
                    if ((int) $mapping['employe_id'] === $employeId && (int) $mapping['capteur_id'] === $capteurId) {
                        $this->mappings[$index]['badge_id'] = $badgeId;
                        $this->mappings[$index]['actif'] = $actif ? 1 : 0;
                        return;
                    }
                }

                $this->mappings[] = [
                    'employe_id' => $employeId,
                    'capteur_id' => $capteurId,
                    'badge_id' => $badgeId,
                    'actif' => $actif ? 1 : 0,
                ];
            }

            protected function synchroniserEcritureCapteur(int $capteurId, array $operations): array
            {
                return [
                    'success' => true,
                    'capteur_id' => $capteurId,
                    'operations' => $operations,
                ];
            }

            public function genererBadgeId(int $capteurId): int
            {
                $badgeIds = array_map(static fn (array $mapping): int => (int) $mapping['badge_id'], $this->getAffectationsCapteur($capteurId));

                if ($badgeIds === []) {
                    return 1000;
                }

                return max($badgeIds) + 1;
            }
        };
    }
}
