<?php

namespace App\Commands;

use App\Services\CalculPresenceService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Recalcule les projections de présence d'un tenant pour une journée.
 */
class PointagesRecalculerJournee extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:recalculer-journee';
    protected $description = 'Recalcule les projections de présence d’un tenant pour une journée.';
    protected $usage = 'php spark pointages:recalculer-journee {date} --tenant=X [--employe=Y]';
    protected $arguments = [
        'date' => 'Date de présence au format AAAA-MM-JJ.',
    ];
    protected $options = [
        '--tenant' => 'Identifiant obligatoire du tenant.',
        '--employe' => 'Identifiant facultatif de l’employé.',
    ];

    public function run(array $params): int
    {
        $date = $params[0] ?? null;
        $tenantOption = $this->option('tenant');
        $employeOption = $this->option('employe');
        $tenantId = $this->identifiantPositif($tenantOption);
        $employeId = $employeOption === null ? null : $this->identifiantPositif($employeOption);

        if (! is_string($date) || ! $this->dateValide($date)) {
            CLI::error('La date est obligatoire et doit respecter le format AAAA-MM-JJ.');

            return EXIT_ERROR;
        }
        if ($tenantId === null) {
            CLI::error('L’option --tenant est obligatoire et doit être un entier positif.');

            return EXIT_ERROR;
        }
        if ($employeOption !== null && $employeId === null) {
            CLI::error('L’option --employe doit être un entier positif.');

            return EXIT_ERROR;
        }

        try {
            $employes = $this->chargerEmployes($tenantId, $employeId);
            if ($employes === []) {
                CLI::error('Aucun employé actif trouvé pour les filtres indiqués.');

                return EXIT_ERROR;
            }

            $succes = 0;
            $ignorees = 0;
            $erreurs = 0;
            foreach ($employes as $employe) {
                $id = (int) $employe['id'];
                try {
                    if ($this->recalculer($id, $date) > 0) {
                        $succes++;
                    } else {
                        $ignorees++;
                    }
                } catch (Throwable $exception) {
                    $erreurs++;
                    CLI::error(sprintf('Employé #%d : %s', $id, $exception->getMessage()));
                }
            }

            CLI::write(sprintf(
                'Tenant #%d — %s : traités=%d, succès=%d, ignorés=%d, erreurs=%d',
                $tenantId,
                $date,
                count($employes),
                $succes,
                $ignorees,
                $erreurs
            ), $erreurs > 0 ? 'yellow' : 'green');

            return $erreurs > 0 ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Échec du recalcul des présences : ' . $exception->getMessage());

            return EXIT_ERROR;
        }
    }

    protected function option(string $name): string|bool|null
    {
        return CLI::getOption($name);
    }

    /** @return array<int, array{id:int|string}> */
    protected function chargerEmployes(int $tenantId, ?int $employeId): array
    {
        $requete = Database::connect()->table('employes')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null);
        if ($employeId !== null) {
            $requete->where('id', $employeId);
        }

        return $requete->orderBy('id', 'ASC')->get()->getResultArray();
    }

    protected function recalculer(int $employeId, string $date): int
    {
        return (new CalculPresenceService())->recalculerJournee($employeId, $date);
    }

    private function identifiantPositif(string|bool|null $valeur): ?int
    {
        if (! is_string($valeur)) {
            return null;
        }
        $identifiant = filter_var($valeur, FILTER_VALIDATE_INT);

        return $identifiant !== false && $identifiant > 0 ? $identifiant : null;
    }

    private function dateValide(string $date): bool
    {
        $dateParsee = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $dateParsee !== false && $dateParsee->format('Y-m-d') === $date;
    }
}
