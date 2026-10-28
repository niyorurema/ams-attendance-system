<?php

use App\Services\RapportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RapportServiceTest extends CIUnitTestCase
{
    public function testExtraitLesHeuresDeRetardEtDeDepartAnticipe(): void
    {
        $service = new class extends RapportService {
            public function __construct()
            {
            }

            public function arrival(array $analyse): ?string
            {
                return $this->heureArriveeTardive($analyse);
            }

            public function earlyDeparture(array $analyse): ?string
            {
                return $this->heureDepartAnticipe($analyse);
            }
        };
        $analyse = [
            ['type' => 'CHECK_IN', 'reel' => '09:04:42', 'statut' => 'RETARD', 'ecart_min' => 64],
            ['type' => 'BREAK_IN', 'reel' => '12:05:00', 'statut' => 'RETARD', 'ecart_min' => 5],
            ['type' => 'CHECK_OUT', 'reel' => '16:45:00', 'statut' => 'OK', 'ecart_min' => -15],
        ];

        $this->assertSame('09:04:42', $service->arrival($analyse));
        $this->assertSame('16:45:00', $service->earlyDeparture($analyse));
    }

    public function testNIndiquePasDeRetardOuDeDepartAnticipeSansEcart(): void
    {
        $service = new class extends RapportService {
            public function __construct()
            {
            }

            public function arrival(array $analyse): ?string
            {
                return $this->heureArriveeTardive($analyse);
            }

            public function earlyDeparture(array $analyse): ?string
            {
                return $this->heureDepartAnticipe($analyse);
            }
        };
        $analyse = [
            ['type' => 'CHECK_IN', 'reel' => '08:00:00', 'statut' => 'OK', 'ecart_min' => 0],
            ['type' => 'CHECK_OUT', 'reel' => '17:00:00', 'statut' => 'OK', 'ecart_min' => 0],
        ];

        $this->assertNull($service->arrival($analyse));
        $this->assertNull($service->earlyDeparture($analyse));
    }

    public function testToutesLesEntreesQuotidiennesUtilisentLeTraitementJ1Unique(): void
    {
        $service = new class extends RapportService {
            public int $preparations = 0;

            public function __construct()
            {
            }

            public function contexteRapportsQuotidiens(): array
            {
                $this->preparations++;

                return [];
            }
        };

        $resultatCommande = $service->envoyerRapportsQuotidiens();
        $resultatType = $service->envoyerRapportsParType('QUOTIDIEN');

        $this->assertSame(['traite' => 0, 'envoyes' => 0, 'ignores' => 0, 'erreurs' => 0], $resultatCommande);
        $this->assertSame($resultatCommande, $resultatType);
        $this->assertSame(2, $service->preparations);
    }

    public function testPreparationUnitaireDelegueAuMemeGenerateurAvecLePerimetre(): void
    {
        $service = new class extends RapportService {
            /** @var array{int,string,string,?int}|null */
            public ?array $arguments = null;

            public function __construct()
            {
            }

            public function genererRapportQuotidien(
                int $userId,
                string $date,
                ?string $dateMandat = null,
                ?int $uniteScopeId = null
            ): array {
                $this->arguments = [$userId, $date, (string) $dateMandat, $uniteScopeId];

                return ['date' => $date];
            }
        };

        $rapport = $service->preparerRapportPourDestinataire(
            ['user_id' => 42, 'unite_id' => 17],
            '2026-10-23',
            '2026-10-24'
        );

        $this->assertSame(['date' => '2026-10-23'], $rapport);
        $this->assertSame([42, '2026-10-23', '2026-10-24', 17], $service->arguments);
    }
}
