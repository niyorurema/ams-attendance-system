<?php

use App\Commands\PointagesRecalculerJournee;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PointagesRecalculerJourneeCommandTest extends CIUnitTestCase
{
    public function testArgumentDateObligatoire(): void
    {
        $this->assertSame(EXIT_ERROR, $this->commande()->executer([]));
    }

    public function testArgumentTenantObligatoire(): void
    {
        $this->assertSame(EXIT_ERROR, $this->commande(['tenant' => null])->executer(['2026-10-19']));
    }

    public function testArgumentDateFormatInvalide(): void
    {
        $this->assertSame(EXIT_ERROR, $this->commande()->executer(['19-10-2026']));
    }

    public function testRecalculAvecEmployeSpecifique(): void
    {
        $commande = $this->commande(['employe' => '42'], [['id' => 42]], [42 => 17]);

        $this->assertSame(EXIT_SUCCESS, $commande->executer(['2026-10-19']));
        $this->assertSame([42], $commande->employesRecalcules);
    }

    public function testRecalculAvecPlusieursEmployes(): void
    {
        $commande = $this->commande([], [['id' => 3], ['id' => 8]], [3 => 10, 8 => 0]);

        $this->assertSame(EXIT_SUCCESS, $commande->executer(['2026-10-19']));
        $this->assertSame([3, 8], $commande->employesRecalcules);
    }

    public function testExitCode1SiErreurs(): void
    {
        $commande = $this->commande([], [['id' => 3], ['id' => 8]], [3 => 10], [8]);

        $this->assertSame(EXIT_ERROR, $commande->executer(['2026-10-19']));
        $this->assertSame([3, 8], $commande->employesRecalcules);
    }

    /**
     * @param array<string, string|null> $options
     * @param array<int, array{id:int}> $employes
     * @param array<int, int> $resultats
     * @param array<int, int> $erreurs
     */
    private function commande(
        array $options = [],
        array $employes = [],
        array $resultats = [],
        array $erreurs = []
    ): PointagesRecalculerJournee {
        return new class($options, $employes, $resultats, $erreurs) extends PointagesRecalculerJournee {
            /** @var array<int, int> */
            public array $employesRecalcules = [];

            public function __construct(
                private readonly array $optionsTest,
                private readonly array $employesTest,
                private readonly array $resultatsTest,
                private readonly array $erreursTest
            ) {
            }

            public function executer(array $params): int
            {
                ob_start();
                try {
                    return $this->run($params);
                } finally {
                    ob_end_clean();
                }
            }

            protected function option(string $name): string|bool|null
            {
                if ($name === 'tenant' && ! array_key_exists('tenant', $this->optionsTest)) {
                    return '1';
                }

                return $this->optionsTest[$name] ?? null;
            }

            protected function chargerEmployes(int $tenantId, ?int $employeId): array
            {
                return $this->employesTest;
            }

            protected function recalculer(int $employeId, string $date): int
            {
                $this->employesRecalcules[] = $employeId;
                if (in_array($employeId, $this->erreursTest, true)) {
                    throw new RuntimeException('Erreur simulée');
                }

                return $this->resultatsTest[$employeId] ?? 0;
            }
        };
    }
}
