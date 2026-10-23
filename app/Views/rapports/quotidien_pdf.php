<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 9pt; color: #202927; }
        h1 { font-size: 16pt; margin-bottom: 4mm; }
        h2 { font-size: 11pt; margin: 7mm 0 2mm; }
        p { margin: 1mm 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.2mm solid #b9c7c3; padding: 1.5mm; text-align: left; }
        th { background: #dDEBE8; font-weight: bold; }
        .stats td:first-child { width: 30%; font-weight: bold; }
        .details { font-size: 6.5pt; }
        .number { text-align: right; }
        .alerts { margin: 0; padding-left: 5mm; }
    </style>
</head>
<body>
    <h1><?= esc($rapport['titre'] ?? 'Rapport de présence') ?></h1>
    <p><strong>Destinataire :</strong> <?= esc($rapport['destinataire']['nom'] ?? '') ?></p>
    <p><strong>Unité :</strong> <?= esc($rapport['destinataire']['unite'] ?? '') ?></p>

    <h2>Synthèse</h2>
    <table class="stats">
        <tbody>
            <tr><td>Total employés</td><td><?= (int) ($rapport['stats']['total_employes'] ?? 0) ?></td><td>Présents</td><td><?= (int) ($rapport['stats']['presents'] ?? 0) ?></td></tr>
            <tr><td>Absents</td><td><?= (int) ($rapport['stats']['absents'] ?? 0) ?></td><td>Normaux</td><td><?= (int) ($rapport['stats']['normaux'] ?? 0) ?></td></tr>
            <tr><td>Jours fériés</td><td><?= (int) ($rapport['stats']['feries'] ?? 0) ?></td><td>Retards</td><td><?= (int) ($rapport['stats']['retards'] ?? 0) ?></td></tr>
            <tr><td>Repos</td><td><?= (int) ($rapport['stats']['repos'] ?? 0) ?></td><td>Anormaux</td><td><?= (int) ($rapport['stats']['anormaux'] ?? 0) ?></td></tr>
            <tr><td>Heures totales</td><td><?= number_format((float) ($rapport['stats']['heures_totales'] ?? 0), 2, ',', ' ') ?></td><td>Moyenne heures/jour</td><td><?= number_format((float) ($rapport['stats']['moyenne_heures'] ?? 0), 2, ',', ' ') ?></td></tr>
            <tr><td>Taux de présence</td><td colspan="3"><?= number_format((float) ($rapport['stats']['taux_presence'] ?? 0), 2, ',', ' ') ?> %</td></tr>
        </tbody>
    </table>

    <h2>Détails des présences</h2>
    <table class="details">
        <thead>
            <tr>
                <th>Date</th><th>Nom employé</th><th>Matricule</th><th>Équipe / service / direction</th>
                <th>Détails de pointage</th><th>Heures travaillées</th><th>Arrivée en retard</th>
                <th>Départ anticipé</th><th>Retard (min)</th><th>Départ anticipé (min)</th><th>Statut de pointage</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($rapport['details'] ?? []) as $detail): ?>
                <tr>
                    <td><?= esc($rapport['date'] ?? '') ?></td>
                    <td><?= esc(trim(($detail['nom'] ?? '') . ' ' . ($detail['prenom'] ?? ''))) ?></td>
                    <td><?= esc($detail['matricule'] ?? '') ?></td>
                    <td><?= esc($detail['unite'] ?? '') ?></td>
                    <td><?= esc(implode(', ', array_map(
                        static fn (array $pointage): string => (string) ($pointage['type'] ?? '')
                            . ' ' . (string) ($pointage['heure'] ?? ''),
                        $detail['pointages'] ?? []
                    )) ?: '—') ?></td>
                    <td class="number"><?= number_format((float) ($detail['heures_travaillees'] ?? 0), 2, ',', ' ') ?></td>
                    <td><?= esc($detail['arrivee_tardive'] ?? '—') ?></td>
                    <td><?= esc($detail['depart_anticipe'] ?? '—') ?></td>
                    <td class="number"><?= (int) ($detail['retard_minutes'] ?? 0) ?></td>
                    <td class="number"><?= (int) ($detail['depart_anticipe_minutes'] ?? 0) ?></td>
                    <td><?= esc(match ($detail['statut'] ?? '') {
                        'FERIE' => 'Jour férié',
                        'REPOS' => 'Repos',
                        default => $detail['statut'] ?? '',
                    }) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (! empty($rapport['alertes'])): ?>
        <h2>Alertes de pointage</h2>
        <ul class="alerts">
            <?php foreach ($rapport['alertes'] as $alerte): ?>
                <li><?= esc($alerte) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html>
