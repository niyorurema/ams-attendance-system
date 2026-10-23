<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Mpdf\Mpdf;
use PHPMailer\PHPMailer\PHPMailer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Génère les rapports de présence selon les droits hiérarchiques.
 */
class RapportService
{
    protected BaseConnection $db;
    protected UniteOrganisationnelleService $uniteService;
    protected CalculPresenceService $calculPresenceService;

    public function __construct(?UniteOrganisationnelleService $uniteService = null, ?CalculPresenceService $calculPresenceService = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->uniteService = $uniteService ?? new UniteOrganisationnelleService($this->db);
        $this->calculPresenceService = $calculPresenceService ?? new CalculPresenceService(null, $this->db);
    }

    /** @return array<int, array<string, mixed>> */
    public function getEmployesVisibles(int $userId, ?string $date = null, ?int $uniteScopeId = null): array
    {
        $contexte = $this->getContexteUtilisateur($userId);
        if ($contexte === null || ($contexte['role'] === 'lecteur' && $uniteScopeId === null)) { return []; }

        $requete = $this->db->table('employes e')->select('e.id, e.matricule, e.nom, e.prenom, e.unite_id, u.nom AS unite_nom')->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = e.tenant_id', 'left')->where('e.tenant_id', $contexte['tenant_id'])->where('e.actif', 1)->where('e.deleted_at', null);
        if ($uniteScopeId !== null) {
            $unite = $this->db->table('unites_organisationnelles')
                ->select('id')
                ->where('id', $uniteScopeId)
                ->where('tenant_id', (int) $contexte['tenant_id'])
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($unite === null) {
                throw new \InvalidArgumentException('Unité de périmètre invalide pour ce tenant.');
            }
            $uniteIds = [$uniteScopeId];
            foreach ($this->uniteService->getUnitesDescendantes($uniteScopeId) as $descendante) {
                $uniteIds[] = (int) $descendante['id'];
            }
            $requete->whereIn('e.unite_id', array_values(array_unique($uniteIds)));
        } elseif ((int) $contexte['is_super_admin'] !== 1) {
            $dateMandat = $date ?? date('Y-m-d');
            $mandats = $this->db->table('unit_chefs m')
                ->select('m.unite_id')
                ->join(
                    'unites_organisationnelles u',
                    'u.id = m.unite_id AND u.tenant_id = m.tenant_id AND u.actif = 1 AND u.deleted_at IS NULL',
                    'inner'
                )
                ->where('m.tenant_id', (int) $contexte['tenant_id'])
                ->where('m.user_id', $userId)
                ->where('m.est_actif', 1)
                ->where('m.deleted_at', null)
                ->where('m.date_debut <=', $dateMandat)
                ->groupStart()
                    ->where('m.date_fin', null)
                    ->orWhere('m.date_fin >=', $dateMandat)
                ->groupEnd()
                ->get()
                ->getResultArray();
            $uniteIds = array_values(array_unique(array_map(
                static fn (array $mandat): int => (int) $mandat['unite_id'],
                $mandats
            )));
            if ($uniteIds === []) {
                if (empty($contexte['unite_id'])) { return []; }
                $uniteIds[] = (int) $contexte['unite_id'];
            }
            if (in_array($contexte['role'], ['chef_service', 'directeur', 'commissaire'], true) || $mandats !== []) {
                foreach (array_values($uniteIds) as $uniteId) {
                    foreach ($this->uniteService->getUnitesDescendantes($uniteId) as $unite) {
                        $uniteIds[] = (int) $unite['id'];
                    }
                }
            }
            $requete->whereIn('e.unite_id', array_unique($uniteIds));
        }
        return $requete->orderBy('e.nom')->orderBy('e.prenom')->get()->getResultArray();
    }

    /** @return array<string, mixed> */
    public function genererRapportQuotidien(
        int $userId,
        string $date,
        ?string $dateMandat = null,
        ?int $uniteScopeId = null
    ): array
    {
        $contexte = $this->getContexteUtilisateur($userId);
        if ($contexte === null) { throw new \RuntimeException('Utilisateur introuvable.'); }
        $details = [];
        $alertes = [];
        $employesPlanifies = 0;
        $stats = [
            'total_employes' => 0,
            'presents' => 0,
            'absents' => 0,
            'normaux' => 0,
            'retards' => 0,
            'anormaux' => 0,
            'feries' => 0,
            'repos' => 0,
            'heures_totales' => 0.0,
        ];
        $jourTenant = (new JourTravailleService())->verifierJour((int) $contexte['tenant_id'], $date);
        foreach ($this->getEmployesVisibles($userId, $dateMandat ?? $date, $uniteScopeId) as $employe) {
            $calcul = $this->calculPresenceService->calculerJournee((int) $employe['id'], $date);
            $analyse = $calcul['analyse_plages'];
            if (empty($calcul['jour_ferie']) || ! empty($calcul['ferie_travail'])) {
                $employesPlanifies++;
            }
            $details[] = [
                'matricule' => $employe['matricule'],
                'nom' => $employe['nom'],
                'prenom' => $employe['prenom'],
                'unite' => $employe['unite_nom'],
                'heure_arrivee' => $this->heureReelle($analyse, 'CHECK_IN'),
                'heure_depart' => $this->heureReelle($analyse, 'CHECK_OUT'),
                'pointages' => array_values(array_filter(array_map(
                    static fn (array $plage): ?array => $plage['reel'] === null
                        ? null
                        : ['type' => $plage['type'], 'heure' => $plage['reel']],
                    $analyse
                ))),
                'heures_travaillees' => $calcul['heures_travaillees'],
                'arrivee_tardive' => $this->heureArriveeTardive($analyse),
                'depart_anticipe' => $this->heureDepartAnticipe($analyse),
                'retard_minutes' => $calcul['retard_total_minutes'],
                'depart_anticipe_minutes' => array_sum(array_map(
                    static fn (array $plage): int => $plage['type'] === 'CHECK_OUT'
                        ? max(0, -(int) ($plage['ecart_min'] ?? 0))
                        : 0,
                    $analyse
                )),
                'statut' => $calcul['statut'],
                'jour_ferie' => (bool) ($calcul['jour_ferie'] ?? false),
                'jour_ferie_nom' => $calcul['jour_ferie_nom'] ?? null,
                'prime_potentielle' => (bool) ($calcul['prime_potentielle'] ?? false),
                'nb_pointages' => count(array_filter($analyse, static fn (array $plage): bool => $plage['reel'] !== null)),
            ];
            $stats['total_employes']++;
            $stats['heures_totales'] += $calcul['heures_travaillees'];
            if (! empty($calcul['jour_ferie'])) {
                $stats['feries']++;
            }
            match ($calcul['statut']) {
                'ABSENT' => $stats['absents']++,
                'NORMAL' => [$stats['presents']++, $stats['normaux']++],
                'RETARD' => [$stats['presents']++, $stats['retards']++],
                'EN_COURS' => $stats['presents']++,
                'FERIE' => null,
                'REPOS' => $stats['repos']++,
                default => [$stats['presents']++, $stats['anormaux']++],
            };
            foreach ($calcul['pointages_manquants'] as $manquant) {
                $alertes[] = $employe['nom'] . ' ' . $employe['prenom'] . ' : pointage ' . $manquant . ' manquant';
            }
        }
        $stats['heures_totales'] = round($stats['heures_totales'], 2);
        $stats['moyenne_heures'] = $employesPlanifies <= 0
            ? 0.0
            : round($stats['heures_totales'] / $employesPlanifies, 2);
        $employesComptabilises = $stats['presents'] + $stats['absents'];
        $stats['taux_presence'] = $employesComptabilises === 0
            ? 0.0
            : round($stats['presents'] * 100 / $employesComptabilises, 2);
        return [
            'titre' => 'Rapport de présence du ' . date('d/m/Y', strtotime($date)),
            'destinataire' => ['user_id' => $userId, 'tenant_id' => (int) $contexte['tenant_id'], 'nom' => $contexte['nom_complet'], 'role' => $contexte['role'], 'unite' => $contexte['unite_nom']],
            'date' => $date,
            'horaire' => 'Standard OBR',
            'est_jour_travaille' => $jourTenant['travaille'],
            'raison_non_travaille' => $jourTenant['travaille'] ? null : $jourTenant['raison'],
            'stats' => $stats,
            'details' => $details,
            'alertes' => $alertes,
        ];
    }

    /** Génère un PDF paysage et retourne son chemin temporaire. */
    public function genererPDF(array $rapport): string
    {
        $repertoire = $this->repertoireRapports();
        $chemin = $repertoire . DIRECTORY_SEPARATOR . $this->nomFichier($rapport, 'pdf');
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_left' => 10,
            'margin_right' => 10,
            'tempDir' => $repertoire,
        ]);
        $mpdf->WriteHTML(view('rapports/quotidien_pdf', ['rapport' => $rapport]));
        $mpdf->Output($chemin, \Mpdf\Output\Destination::FILE);

        return $chemin;
    }

    /** Génère un classeur Excel de synthèse et de détails. */
    public function genererExcel(array $rapport): string
    {
        $repertoire = $this->repertoireRapports();
        $chemin = $repertoire . DIRECTORY_SEPARATOR . $this->nomFichier($rapport, 'xlsx');
        $classeur = new Spreadsheet();
        $synthese = $classeur->getActiveSheet();
        $synthese->setTitle('Synthèse');
        $this->setTexte($synthese, 'A1', (string) ($rapport['titre'] ?? 'Rapport de présence'));
        $this->setTexte($synthese, 'A2', 'Date');
        $this->setTexte($synthese, 'B2', (string) ($rapport['date'] ?? ''));
        $this->setTexte($synthese, 'A4', 'Indicateur');
        $this->setTexte($synthese, 'B4', 'Valeur');

        $statistiques = $rapport['stats'] ?? [];
        $libelles = [
            'total_employes' => 'Total employés',
            'presents' => 'Présents',
            'absents' => 'Absents',
            'feries' => 'Jours fériés',
            'repos' => 'Jours de repos',
            'normaux' => 'Normaux',
            'retards' => 'Retards',
            'anormaux' => 'Anormaux',
            'heures_totales' => 'Heures totales',
            'moyenne_heures' => 'Moyenne heures/jour',
            'taux_presence' => 'Taux de présence',
        ];
        $ligne = 5;
        foreach ($libelles as $cle => $libelle) {
            $this->setTexte($synthese, 'A' . $ligne, $libelle);
            $this->setNombre($synthese, 'B' . $ligne, (float) ($statistiques[$cle] ?? 0));
            $ligne++;
        }
        $this->styliserEntete($synthese, 'A4:B4');
        $synthese->getColumnDimension('A')->setAutoSize(true);
        $synthese->getColumnDimension('B')->setAutoSize(true);

        $details = $classeur->createSheet();
        $details->setTitle('Détails');
        $entetes = [
            'Date',
            'Matricule',
            'Nom',
            'Prénom',
            'Unité',
            'Détails pointages',
            'Heure arrivée',
            'Heure départ',
            'Heures travaillées',
            'Arrivée tardive',
            'Départ anticipé',
            'Retard (min)',
            'Départ anticipé (min)',
            'Statut',
            'Nombre de pointages',
        ];
        foreach ($entetes as $index => $entete) {
            $this->setTexte($details, Coordinate::stringFromColumnIndex($index + 1) . '1', $entete);
        }
        $this->styliserEntete($details, 'A1:O1');

        foreach (($rapport['details'] ?? []) as $index => $detail) {
            $ligne = $index + 2;
            $this->setTexte($details, 'A' . $ligne, (string) ($rapport['date'] ?? ''));
            foreach (['matricule', 'nom', 'prenom', 'unite'] as $colonne => $cle) {
                $this->setTexte($details, Coordinate::stringFromColumnIndex($colonne + 2) . $ligne, (string) ($detail[$cle] ?? ''));
            }
            $pointages = array_map(
                static fn (array $pointage): string => (string) ($pointage['type'] ?? '')
                    . ' ' . (string) ($pointage['heure'] ?? ''),
                $detail['pointages'] ?? []
            );
            $this->setTexte($details, 'F' . $ligne, implode(', ', $pointages));
            $this->setTexte($details, 'G' . $ligne, (string) ($detail['heure_arrivee'] ?? ''));
            $this->setTexte($details, 'H' . $ligne, (string) ($detail['heure_depart'] ?? ''));
            $this->setNombre($details, 'I' . $ligne, (float) ($detail['heures_travaillees'] ?? 0));
            $this->setTexte($details, 'J' . $ligne, (string) ($detail['arrivee_tardive'] ?? ''));
            $this->setTexte($details, 'K' . $ligne, (string) ($detail['depart_anticipe'] ?? ''));
            $this->setNombre($details, 'L' . $ligne, (int) ($detail['retard_minutes'] ?? 0));
            $this->setNombre($details, 'M' . $ligne, (int) ($detail['depart_anticipe_minutes'] ?? 0));
            $this->setTexte($details, 'N' . $ligne, (string) ($detail['statut'] ?? ''));
            $this->setNombre($details, 'O' . $ligne, (int) ($detail['nb_pointages'] ?? 0));
        }
        $details->freezePane('A2');
        $details->setAutoFilter('A1:O' . max(1, count($rapport['details'] ?? []) + 1));
        foreach (range('A', 'O') as $colonne) {
            $details->getColumnDimension($colonne)->setAutoSize(true);
        }

        (new Xlsx($classeur))->save($chemin);
        $classeur->disconnectWorksheets();

        return $chemin;
    }

    private function repertoireRapports(): string
    {
        $repertoire = WRITEPATH . 'rapports';
        if (! is_dir($repertoire) && ! mkdir($repertoire, 0750, true) && ! is_dir($repertoire)) {
            throw new \RuntimeException('Impossible de créer le répertoire des rapports.');
        }

        return $repertoire;
    }

    private function nomFichier(array $rapport, string $extension): string
    {
        $date = preg_replace('/[^0-9-]/', '', (string) ($rapport['date'] ?? date('Y-m-d')));

        return sprintf('rapport_%s_%s.%s', $date, bin2hex(random_bytes(6)), $extension);
    }

    private function setTexte(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $cellule, string $valeur): void
    {
        $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_STRING);
    }

    private function setNombre(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $cellule, float|int $valeur): void
    {
        $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_NUMERIC);
    }

    private function styliserEntete(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, string $plage): void
    {
        $feuille->getStyle($plage)->getFont()->setBold(true);
        $feuille->getStyle($plage)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBE8');
    }

    /** Envoie un rapport au compte actif du tenant et journalise le résultat. */
    public function envoyerEmail(
        int $userId,
        array $rapport,
        string $format = 'PDF',
        string $langue = 'fr',
        ?int $rapportPlanifieId = null,
        ?int $rapportJournalierConfigId = null,
        ?int $rapportUniteId = null
    ): bool
    {
        $format = strtoupper($format);
        $langue = in_array($langue, ['fr', 'en', 'rn'], true) ? $langue : 'fr';
        $tenantId = (int) ($rapport['destinataire']['tenant_id'] ?? 0);
        $user = $this->db->table('users')
            ->select('id, tenant_id, email, nom_complet')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($tenantId < 1 || $user === null || ! filter_var($user['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Destinataire introuvable ou adresse e-mail invalide pour ce tenant.');
        }
        if (! in_array($format, ['PDF', 'EXCEL'], true)) {
            throw new \InvalidArgumentException('Format invalide (PDF ou EXCEL).');
        }

        $maintenant = date('Y-m-d H:i:s');
        $this->db->table('rapports_envoyes')->insert([
            'tenant_id' => $tenantId,
            'rapport_planifie_id' => $rapportPlanifieId,
            'rapport_journalier_config_id' => $rapportJournalierConfigId,
            'rapport_unite_id' => $rapportUniteId,
            'user_id' => $userId,
            'date_rapport' => (string) $rapport['date'],
            'heure_envoi' => $maintenant,
            'format' => $format,
            'fichier_path' => null,
            'contenu_json' => json_encode($rapport, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'destinataire_email' => (string) $user['email'],
            'destinataire_nom' => (string) $user['nom_complet'],
            'statut' => 'EN_ATTENTE',
            'message_erreur' => null,
            'created_at' => $maintenant,
        ]);
        if ($this->db->affectedRows() !== 1) {
            throw new \RuntimeException('Impossible de créer l’archive du rapport avant son envoi.');
        }
        $historiqueId = (int) $this->db->insertID();
        if ($historiqueId < 1) {
            throw new \RuntimeException('Identifiant d’archive du rapport invalide.');
        }

        $fichier = null;
        try {
            $fichier = $format === 'PDF' ? $this->genererPDF($rapport) : $this->genererExcel($rapport);
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) env('EMAIL_HOST', '');
            $mail->Port = (int) env('EMAIL_PORT', 25);
            $mail->SMTPAuth = (string) env('EMAIL_USER', '') !== '';
            $mail->Username = (string) env('EMAIL_USER', '');
            $mail->Password = (string) env('EMAIL_PASS', '');
            $chiffrement = strtolower((string) env('EMAIL_ENCRYPTION', 'tls'));
            $mail->SMTPSecure = $chiffrement === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : ($chiffrement === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom((string) env('EMAIL_FROM', ''), (string) env('EMAIL_FROM_NAME', 'AMS'));
            $mail->addAddress((string) $user['email'], (string) $user['nom_complet']);
            $mail->isHTML(true);
            $mail->Subject = $this->sujetRapport((string) ($rapport['date'] ?? ''), $langue);
            $mail->Body = view('rapports/email', ['rapport' => $rapport, 'langue' => $langue]);
            $mail->AltBody = (string) ($rapport['titre'] ?? 'Rapport de présence');
            $mail->addAttachment($fichier, basename($fichier));
            $mail->send();

        } catch (\Throwable $e) {
            log_message('error', 'Envoi du rapport par e-mail impossible : {error}', ['error' => $e->getMessage()]);
            $misAJour = $this->db->table('rapports_envoyes')
                ->where('id', $historiqueId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'statut' => 'ECHEC',
                    'message_erreur' => mb_substr($e->getMessage(), 0, 2000),
                    'heure_envoi' => date('Y-m-d H:i:s'),
                ]);
            if (! $misAJour || $this->db->affectedRows() !== 1) {
                throw new \RuntimeException('L’envoi a échoué et son statut n’a pas pu être archivé.', 0, $e);
            }
            return false;
        } finally {
            if ($fichier !== null && is_file($fichier)) {
                unlink($fichier);
            }
        }

        $this->db->table('rapports_envoyes')
            ->where('id', $historiqueId)
            ->where('tenant_id', $tenantId)
            ->update([
                'statut' => 'ENVOYE',
                'heure_envoi' => date('Y-m-d H:i:s'),
            ]);
        if ($this->db->affectedRows() !== 1) {
            throw new \RuntimeException('Le courriel a été envoyé, mais l’archive n’a pas pu être mise à jour.');
        }

        return true;
    }

    private function sujetRapport(string $date, string $langue): string
    {
        return match ($langue) {
            'en' => 'Attendance report for ' . $date,
            'rn' => 'Raporo y’ivyashikiye ku ' . $date,
            default => 'Rapport de présence du ' . $date,
        };
    }

    /**
     * Envoie les rapports quotidiens planifiés pour la date J-1.
     *
     * @return array<string, int>
     */
    public function envoyerRapportsQuotidiens(): array
    {
        $result = $this->envoyerRapportsAutomatiquesParTenant();
        $legacy = $this->envoyerRapportsParType('QUOTIDIEN');

        foreach ($result as $key => $value) {
            $result[$key] += $legacy[$key] ?? 0;
        }

        return $result;
    }

    /**
     * Envoie le rapport J-1 selon la configuration et les jours travaillés du tenant.
     *
     * @return array<string, int>
     */
    protected function envoyerRapportsAutomatiquesParTenant(): array
    {
        $configs = $this->db->table('rapport_journalier_configs c')
            ->select('c.*')
            ->join('tenants t', 't.id = c.tenant_id', 'inner')
            ->where('c.actif', 1)
            ->where('t.is_active', 1)
            ->where('t.deleted_at', null)
            ->get()
            ->getResultArray();
        $result = ['traite' => 0, 'envoyes' => 0, 'ignores' => 0, 'erreurs' => 0];
        $joursTravailles = new JourTravailleService();

        foreach ($configs as $config) {
            $tenantId = (int) $config['tenant_id'];
            $maintenant = (new TenantTimezoneService($this->db))->now($tenantId);
            if ((string) $config['heure_envoi'] > $maintenant->format('H:i:s')) {
                continue;
            }
            $result['traite']++;
            $aujourdhui = $maintenant->format('Y-m-d');
            $dateRapport = $maintenant->modify('-1 day')->format('Y-m-d');
            if (! $joursTravailles->estTravaille($tenantId, $aujourdhui)) {
                $result['ignores']++;
                continue;
            }

            $jourFerie = (new JourFerieService())->getJourFerie($tenantId, $dateRapport);
            if ($jourFerie !== null) {
                $result['ignores']++;
                continue;
            }

            $responsables = $this->db->table('unit_chefs m')
                ->select('m.user_id, m.unite_id')
                ->join('users u', 'u.id = m.user_id AND u.tenant_id = m.tenant_id', 'inner')
                ->join('unites_organisationnelles o', 'o.id = m.unite_id AND o.tenant_id = m.tenant_id AND o.actif = 1 AND o.deleted_at IS NULL', 'inner')
                ->where('m.tenant_id', $tenantId)
                ->where('m.est_actif', 1)
                ->where('m.deleted_at', null)
                ->where('m.date_debut <=', $aujourdhui)
                ->where('u.is_active', 1)
                ->where('u.deleted_at', null)
                ->groupStart()
                    ->where('m.date_fin', null)
                    ->orWhere('m.date_fin >=', $aujourdhui)
                ->groupEnd()
                ->groupBy(['m.user_id', 'm.unite_id'])
                ->get()
                ->getResultArray();
            $destinataires = array_map(static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'unite_id' => (int) $row['unite_id'],
            ], $responsables);
            $supplementaires = $this->db->table('rapport_journalier_destinataires_unites')
                ->select('user_id, unite_id')
                ->where('tenant_id', $tenantId)
                ->get()
                ->getResultArray();
            $destinataires = array_merge($destinataires, array_map(static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'unite_id' => $row['unite_id'] === null ? null : (int) $row['unite_id'],
            ], $supplementaires));
            $destinatairesUniques = [];
            foreach ($destinataires as $destinataire) {
                $cle = $destinataire['user_id'] . ':' . ($destinataire['unite_id'] ?? 'global');
                $destinatairesUniques[$cle] = $destinataire;
            }
            $destinataires = array_values($destinatairesUniques);
            if ($destinataires === []) {
                log_message('error', 'Aucun destinataire configuré pour le rapport journalier du tenant {tenant}.', [
                    'tenant' => $tenantId,
                ]);
                $result['erreurs']++;
                continue;
            }

            foreach ($destinataires as $destinataire) {
                $userId = $destinataire['user_id'];
                $uniteId = $destinataire['unite_id'];
                $envoiExistant = $this->db->table('rapports_envoyes')
                    ->where('tenant_id', $tenantId)
                    ->where('rapport_journalier_config_id', (int) $config['id'])
                    ->where('user_id', $userId)
                    ->where('rapport_unite_id', $uniteId)
                    ->where('date_rapport', $dateRapport)
                    ->whereIn('statut', ['ENVOYE', 'EN_ATTENTE'])
                    ->countAllResults() > 0;
                if (! $envoiExistant && $uniteId === null) {
                    $envoiExistant = $this->db->table('rapports_envoyes')
                        ->where('tenant_id', $tenantId)
                        ->where('user_id', $userId)
                        ->where('date_rapport', $dateRapport)
                        ->whereIn('statut', ['ENVOYE', 'EN_ATTENTE'])
                        ->where('rapport_planifie_id IS NOT NULL', null, false)
                        ->countAllResults() > 0;
                }
                if ($envoiExistant) {
                    $result['ignores']++;
                    continue;
                }

                try {
                    $rapport = $this->genererRapportQuotidien($userId, $dateRapport, $aujourdhui, $uniteId);
                    if (! $this->envoyerEmail(
                        $userId,
                        $rapport,
                        (string) $config['format'],
                        (string) $config['langue'],
                        null,
                        (int) $config['id'],
                        $uniteId
                    )) {
                        throw new \RuntimeException('L’envoi du rapport journalier a échoué.');
                    }
                    $result['envoyes']++;
                } catch (\Throwable $e) {
                    log_message('error', 'Échec du rapport journalier du tenant {tenant}, destinataire {user} : {error}', [
                        'tenant' => $tenantId,
                        'user' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    $result['erreurs']++;
                }
            }

            $this->db->table('rapport_journalier_configs')
                ->where('id', (int) $config['id'])
                ->update(['updated_at' => date('Y-m-d H:i:s')]);
        }

        return $result;
    }

    /**
     * Envoie les rapports planifiés selon le type demandé.
     *
     * @param string $type Type de rapport : QUOTIDIEN, HEBDOMADAIRE, MENSUEL.
     * @return array<string, int>
     */
    public function envoyerRapportsParType(string $type): array
    {
        $dateAujourdhui = date('Y-m-d');
        $heureActuelle = date('H:i:s');

        $requete = $this->db->table('rapports_planifies')
            ->where('actif', 1)
            ->where('type', $type);
        if ($type !== 'QUOTIDIEN') {
            $requete->where('heure_envoi <=', $heureActuelle);
        }
        $planifications = $requete->get()->getResultArray();

        $result = ['traite' => 0, 'envoyes' => 0, 'ignores' => 0, 'erreurs' => 0];

        foreach ($planifications as $planification) {
            $dateRapport = $dateAujourdhui;
            if ($type === 'QUOTIDIEN') {
                $tenantId = (int) $planification['tenant_id'];
                $maintenant = (new TenantTimezoneService($this->db))->now($tenantId);
                if ((string) $planification['heure_envoi'] > $maintenant->format('H:i:s')) {
                    continue;
                }
                $dateAujourdhuiTenant = $maintenant->format('Y-m-d');
                $dateRapport = $maintenant->modify('-1 day')->format('Y-m-d');
                if (! (new JourTravailleService())->estTravaille($tenantId, $dateAujourdhuiTenant)) {
                    $result['ignores']++;
                    continue;
                }
                $jourFerie = (new JourFerieService())->getJourFerie($tenantId, $dateRapport);
                if ($jourFerie !== null) {
                    $result['ignores']++;
                    continue;
                }
            }
            $result['traite']++;

            $dejaEnvoyeQuery = $this->db->table('rapports_envoyes')
                ->where('tenant_id', (int) $planification['tenant_id'])
                ->where('user_id', (int) $planification['user_id'])
                ->where('date_rapport', $dateRapport)
                ->whereIn('statut', ['ENVOYE', 'EN_ATTENTE']);
            if ($type === 'QUOTIDIEN') {
                $dejaEnvoyeQuery->groupStart()
                    ->where('rapport_planifie_id', (int) $planification['id'])
                    ->orWhere('rapport_journalier_config_id IS NOT NULL', null, false)
                    ->groupEnd();
            } else {
                $dejaEnvoyeQuery->where('rapport_planifie_id', (int) $planification['id']);
            }
            $dejaEnvoye = $dejaEnvoyeQuery->countAllResults() > 0;

            if ($dejaEnvoye) {
                $result['ignores']++;
                continue;
            }

            try {
                $this->envoyerRapportPlanifie($planification, $dateRapport);
                $result['envoyes']++;
            } catch (\Throwable $e) {
                log_message('error', 'Échec du rapport planifié {planification} : {error}', [
                    'planification' => (int) $planification['id'],
                    'error' => $e->getMessage(),
                ]);
                $result['erreurs']++;
            }
        }

        return $result;
    }

    /**
     * Envoie un rapport planifié donné.
     *
     * @param array<string, mixed> $planification
     * @return array<string, mixed>
     */
    protected function envoyerRapportPlanifie(array $planification, string $dateRapport): array
    {
        $rapport = $this->genererRapportQuotidien((int) $planification['user_id'], $dateRapport);
        if (! $this->envoyerEmail(
            (int) $planification['user_id'],
            $rapport,
            (string) ($planification['format'] ?? 'PDF'),
            (string) ($planification['langue'] ?? 'fr'),
            (int) $planification['id']
        )) {
            throw new \RuntimeException('L’envoi du rapport planifié a échoué.');
        }

        $this->db->table('rapports_planifies')
            ->where('id', (int) $planification['id'])
            ->update([
                'dernier_envoi' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $rapport;
    }

    /** @return ?array<string, mixed> */
    protected function getContexteUtilisateur(int $userId): ?array
    {
        $resultat = $this->db->table('users us')->select('us.id, us.tenant_id, us.role, us.is_super_admin, us.nom_complet, e.unite_id, u.nom AS unite_nom')->join('employes e', 'e.id = us.employe_id AND e.tenant_id = us.tenant_id', 'left')->join('unites_organisationnelles u', 'u.id = e.unite_id AND u.tenant_id = us.tenant_id', 'left')->where('us.id', $userId)->where('us.deleted_at', null)->get()->getRowArray();
        return $resultat ?: null;
    }
    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureReelle(array $analyse, string $type): ?string { foreach ($analyse as $plage) { if ($plage['type'] === $type) { return $plage['reel']; } } return null; }

    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureSelonAnalyse(array $analyse, string $type, callable $condition): ?string
    {
        foreach ($analyse as $plage) {
            if ($plage['type'] === $type && $plage['reel'] !== null && $condition($plage)) {
                return (string) $plage['reel'];
            }
        }

        return null;
    }

    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureArriveeTardive(array $analyse): ?string
    {
        return $this->heureSelonAnalyse(
            $analyse,
            'CHECK_IN',
            static fn (array $plage): bool => $plage['statut'] === 'RETARD'
        );
    }

    /** @param array<int, array<string,mixed>> $analyse */
    protected function heureDepartAnticipe(array $analyse): ?string
    {
        return $this->heureSelonAnalyse(
            $analyse,
            'CHECK_OUT',
            static fn (array $plage): bool => (int) ($plage['ecart_min'] ?? 0) < 0
        );
    }
}
