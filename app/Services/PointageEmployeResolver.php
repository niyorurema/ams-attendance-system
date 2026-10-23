<?php

namespace App\Services;

/**
 * Résout l’employé d’un pointage en privilégiant son matricule capteur.
 */
class PointageEmployeResolver
{
    /**
     * @param array<string, array<string, mixed>> $employesParMatricule
     * @param array<string, array<string, mixed>> $employesParBadge
     * @return array<string, mixed>|null
     */
    public function resoudre(
        mixed $matricule,
        mixed $badgeId,
        array $employesParMatricule,
        array $employesParBadge
    ): ?array {
        $matriculeNormalise = $this->normaliser($matricule);
        if ($matriculeNormalise !== null && isset($employesParMatricule[$matriculeNormalise])) {
            return $employesParMatricule[$matriculeNormalise];
        }

        $badgeNormalise = $this->normaliser($badgeId);

        return $badgeNormalise !== null ? ($employesParBadge[$badgeNormalise] ?? null) : null;
    }

    private function normaliser(mixed $identifiant): ?string
    {
        if (! is_string($identifiant) && ! is_int($identifiant)) {
            return null;
        }

        $identifiant = trim((string) $identifiant);

        return $identifiant === '' ? null : $identifiant;
    }
}
