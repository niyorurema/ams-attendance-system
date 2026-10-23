<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Détermine le type et le statut calculé des pointages d'une journée.
 */
final class PointageTypeService
{
    private const TYPE_INCONNU = 'UNKNOWN';
    /**
     * @param array<int, array<string, mixed>> $pointages
     * @param array<int, array<string, mixed>> $plagesHoraires
     * @return array<int, array<string, mixed>>
     */
    public function determinerTypesEtStatuts(
        array $pointages,
        array $plagesHoraires,
        ?int $toleranceOverride = null
    ): array
    {
        if ($toleranceOverride !== null && $toleranceOverride < 0) {
            throw new \InvalidArgumentException('La tolérance ne peut pas être négative.');
        }
        if ($pointages === []) {
            return [];
        }

        usort($pointages, static function (array $gauche, array $droite): int {
            $comparaisonDate = strcmp(
                (string) ($gauche['date_pointage'] ?? ''),
                (string) ($droite['date_pointage'] ?? '')
            );

            return $comparaisonDate !== 0
                ? $comparaisonDate
                : ((int) ($gauche['id'] ?? 0) <=> (int) ($droite['id'] ?? 0));
        });

        $plagesParType = $this->indexerPlages($plagesHoraires);
        $nombre = count($pointages);
        foreach ($pointages as $index => &$pointage) {
            if ($nombre === 1 || $index === 0) {
                $type = 'CHECK_IN';
                $plage = $this->premierePlage($plagesParType['CHECK_IN'] ?? []);
            } elseif ($index === $nombre - 1) {
                $type = 'CHECK_OUT';
                $plage = $this->dernierePlage($plagesParType['CHECK_OUT'] ?? []);
            } else {
                $typesIntermediaires = $this->typesIntermediaires($plagesHoraires);
                $positionIntermediaire = $index - 1;
                if ($typesIntermediaires === []) {
                    $type = $positionIntermediaire === 0 ? 'BREAK_OUT' : 'BREAK_IN';
                    $plage = null;
                } else {
                    $type = $typesIntermediaires[min($positionIntermediaire, count($typesIntermediaires) - 1)];
                    $plage = $this->premierePlage($plagesParType[$type] ?? []);
                }
            }

            $pointage['type_evenement'] = $type;
            $pointage['statut_calcul'] = $this->calculerStatut(
                (string) ($pointage['date_pointage'] ?? ''),
                $plage,
                $type,
                $toleranceOverride
            );
        }
        unset($pointage);

        return $pointages;
    }

    /**
     * Simule le classement par rapprochement chronologique aux références des plages.
     *
     * Chaque pointage est associé au plus une fois à une plage, en minimisant
     * l'écart horaire total tout en conservant l'ordre des événements. Les
     * pointages excédentaires sont marqués UNKNOWN plutôt que réaffectés.
     *
     * @param array<int, array<string, mixed>> $pointages
     * @param array<int, array<string, mixed>> $plagesHoraires
     * @return array<int, array<string, mixed>>
     */
    public function simulerTypesEtStatutsParPlage(
        array $pointages,
        array $plagesHoraires,
        ?int $toleranceOverride = null
    ): array {
        if ($toleranceOverride !== null && $toleranceOverride < 0) {
            throw new \InvalidArgumentException('La tolérance ne peut pas être négative.');
        }
        if ($pointages === []) {
            return [];
        }

        usort($pointages, static function (array $gauche, array $droite): int {
            $comparaisonDate = strcmp(
                (string) ($gauche['date_pointage'] ?? ''),
                (string) ($droite['date_pointage'] ?? '')
            );

            return $comparaisonDate !== 0
                ? $comparaisonDate
                : ((int) ($gauche['id'] ?? 0) <=> (int) ($droite['id'] ?? 0));
        });

        $references = $this->referencesHoraires($plagesHoraires);
        if ($references === []) {
            foreach ($pointages as &$pointage) {
                $pointage['type_evenement'] = self::TYPE_INCONNU;
                $pointage['statut_calcul'] = self::TYPE_INCONNU;
            }
            unset($pointage);

            return $pointages;
        }

        $pointageMinutes = array_map(
            fn (array $pointage): ?int => $this->minutesDepuisDateHeure((string) ($pointage['date_pointage'] ?? '')),
            $pointages
        );
        $indicesValides = array_keys(array_filter($pointageMinutes, static fn (?int $minutes): bool => $minutes !== null));
        $associationsValides = $this->associerAuxReferences(
            array_values(array_intersect_key($pointageMinutes, array_flip($indicesValides))),
            $references
        );
        $associations = [];
        foreach ($indicesValides as $indexValide => $indexPointage) {
            if (isset($associationsValides[$indexValide])) {
                $associations[$indexPointage] = $associationsValides[$indexValide];
            }
        }

        foreach ($pointages as $index => &$pointage) {
            $reference = $associations[$index] ?? null;
            if ($reference === null) {
                $pointage['type_evenement'] = self::TYPE_INCONNU;
                $pointage['statut_calcul'] = self::TYPE_INCONNU;
                continue;
            }

            $type = $reference['type'];
            $pointage['type_evenement'] = $type;
            $pointage['statut_calcul'] = $this->calculerStatut(
                (string) ($pointage['date_pointage'] ?? ''),
                ['heure' => $reference['heure'], 'tolerance_min' => $reference['tolerance_min']],
                $type,
                $toleranceOverride
            );
        }
        unset($pointage);

        return $pointages;
    }

    /**
     * Détermine les types configurés entre l'arrivée et le départ.
     *
     * @param array<int, array<string, mixed>> $plagesHoraires
     * @return list<string>
     */
    private function typesIntermediaires(array $plagesHoraires): array
    {
        $types = [];
        foreach ($plagesHoraires as $plage) {
            $type = $this->normaliserType((string) ($plage['type'] ?? ''));
            if (in_array($type, ['BREAK_OUT', 'BREAK_IN'], true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param array<int, array<string, mixed>> $plagesHoraires
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexerPlages(array $plagesHoraires): array
    {
        $resultat = [];
        foreach ($plagesHoraires as $plage) {
            $type = $this->normaliserType((string) ($plage['type'] ?? ''));
            if (in_array($type, ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT'], true)) {
                $resultat[$type][] = $plage;
            }
        }

        return $resultat;
    }

    private function normaliserType(string $type): string
    {
        return match (strtoupper(trim($type))) {
            'BREAK_START' => 'BREAK_OUT',
            'BREAK_END' => 'BREAK_IN',
            default => strtoupper(trim($type)),
        };
    }

    /**
     * @param list<array<string, mixed>> $plages
     * @return array<string, mixed>|null
     */
    private function premierePlage(array $plages): ?array
    {
        return $plages[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $plages
     * @return array<string, mixed>|null
     */
    private function dernierePlage(array $plages): ?array
    {
        return $plages === [] ? null : $plages[array_key_last($plages)];
    }

    /**
     * @param array<string, mixed>|null $plage
     */
    private function calculerStatut(
        string $datePointage,
        ?array $plage,
        string $type,
        ?int $toleranceOverride
    ): string
    {
        if ($plage === null) {
            return self::TYPE_INCONNU;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datePointage);
        $erreurs = DateTimeImmutable::getLastErrors();
        if ($date === false || ($erreurs !== false && ($erreurs['warning_count'] > 0 || $erreurs['error_count'] > 0))) {
            return self::TYPE_INCONNU;
        }

        $heureReference = $plage['heure'] ?? (
            in_array($type, ['CHECK_OUT', 'BREAK_IN'], true)
                ? ($plage['fin'] ?? null)
                : ($plage['debut'] ?? null)
        );
        if (! is_string($heureReference)) {
            return self::TYPE_INCONNU;
        }

        $minutesReference = $this->heureEnMinutes($heureReference);
        if ($minutesReference === null) {
            return self::TYPE_INCONNU;
        }

        $minutesPointage = ((int) $date->format('H') * 60) + (int) $date->format('i');
        $tolerance = $toleranceOverride ?? filter_var($plage['tolerance_min'] ?? 10, FILTER_VALIDATE_INT);
        if ($tolerance === false || $tolerance < 0) {
            return self::TYPE_INCONNU;
        }

        $ecart = $minutesPointage - $minutesReference;
        if ($ecart > $tolerance) {
            return 'RETARD';
        }
        if ($ecart < -$tolerance) {
            return 'ANORMAL';
        }

        return 'VALIDE';
    }

    private function heureEnMinutes(string $heure): ?int
    {
        $heure = substr($heure, 0, 5);
        $date = DateTimeImmutable::createFromFormat('!H:i', $heure);
        if ($date === false || $date->format('H:i') !== $heure) {
            return null;
        }

        return ((int) $date->format('H') * 60) + (int) $date->format('i');
    }

    /**
     * @param array<int, array<string, mixed>> $plagesHoraires
     * @return list<array{type:string,heure:string,minutes:int,tolerance_min:int,ordre:int}>
     */
    private function referencesHoraires(array $plagesHoraires): array
    {
        $references = [];
        foreach ($plagesHoraires as $index => $plage) {
            $type = $this->normaliserType((string) ($plage['type'] ?? ''));
            if (! in_array($type, ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT'], true)) {
                continue;
            }
            $heure = $plage['heure'] ?? (
                in_array($type, ['CHECK_OUT', 'BREAK_IN'], true)
                    ? ($plage['fin'] ?? null)
                    : ($plage['debut'] ?? null)
            );
            if (! is_string($heure)) {
                continue;
            }
            $minutes = $this->heureEnMinutes($heure);
            $tolerance = filter_var($plage['tolerance_min'] ?? 10, FILTER_VALIDATE_INT);
            if ($minutes === null || $tolerance === false || $tolerance < 0) {
                continue;
            }

            $references[] = [
                'type' => $type,
                'heure' => substr($heure, 0, 5),
                'minutes' => $minutes,
                'tolerance_min' => $tolerance,
                'ordre' => $index,
            ];
        }
        usort($references, static fn (array $gauche, array $droite): int =>
            ($gauche['minutes'] <=> $droite['minutes']) ?: ($gauche['ordre'] <=> $droite['ordre'])
        );

        return $references;
    }

    private function minutesDepuisDateHeure(string $dateHeure): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateHeure);
        $erreurs = DateTimeImmutable::getLastErrors();
        if ($date === false || ($erreurs !== false && ($erreurs['warning_count'] > 0 || $erreurs['error_count'] > 0))) {
            return null;
        }

        return ((int) $date->format('H') * 60) + (int) $date->format('i');
    }

    /**
     * @param list<?int> $pointageMinutes
     * @param list<array{type:string,heure:string,minutes:int,tolerance_min:int,ordre:int}> $references
     * @return array<int, array{type:string,heure:string,minutes:int,tolerance_min:int,ordre:int}>
     */
    private function associerAuxReferences(array $pointageMinutes, array $references): array
    {
        $nombrePointages = count($pointageMinutes);
        $nombreReferences = count($references);
        $nombreAssociations = min($nombrePointages, $nombreReferences);
        $memo = [];

        $resoudre = function (int $indexPointage, int $indexReference, int $restants) use (
            &$resoudre,
            &$memo,
            $pointageMinutes,
            $references,
            $nombrePointages,
            $nombreReferences
        ): ?array {
            if ($restants === 0) {
                return ['cout' => 0, 'paires' => []];
            }
            if ($nombrePointages - $indexPointage < $restants
                || $nombreReferences - $indexReference < $restants
            ) {
                return null;
            }

            $cle = $indexPointage . ':' . $indexReference . ':' . $restants;
            if (array_key_exists($cle, $memo)) {
                return $memo[$cle];
            }

            $meilleur = null;
            $correspondance = $resoudre($indexPointage + 1, $indexReference + 1, $restants - 1);
            if ($correspondance !== null) {
                $heure = $pointageMinutes[$indexPointage];
                $ecart = $heure === null ? 0 : abs($heure - $references[$indexReference]['minutes']);
                $meilleur = [
                    'cout' => $ecart + $correspondance['cout'],
                    'paires' => [[$indexPointage, $indexReference], ...$correspondance['paires']],
                ];
            }

            if ($nombrePointages - $indexPointage > $restants) {
                $sansPointage = $resoudre($indexPointage + 1, $indexReference, $restants);
                if ($sansPointage !== null && ($meilleur === null || $sansPointage['cout'] < $meilleur['cout'])) {
                    $meilleur = $sansPointage;
                }
            }
            if ($nombreReferences - $indexReference > $restants) {
                $sansReference = $resoudre($indexPointage, $indexReference + 1, $restants);
                if ($sansReference !== null && ($meilleur === null || $sansReference['cout'] < $meilleur['cout'])) {
                    $meilleur = $sansReference;
                }
            }

            return $memo[$cle] = $meilleur;
        };

        $solution = $resoudre(0, 0, $nombreAssociations);
        $associations = [];
        foreach ($solution['paires'] ?? [] as [$indexPointage, $indexReference]) {
            $associations[$indexPointage] = $references[$indexReference];
        }

        return $associations;
    }
}
