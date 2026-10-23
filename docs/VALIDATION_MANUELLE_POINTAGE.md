# Validation manuelle des règles de pointage

Cette procédure utilise un tenant de démonstration isolé (`id=999`, `slug=demo`)
dans la base `ams`. Le seeder lit le tenant 1 et ses pointages, puis y copie
trois employés et 30 pointages récents par personne. Il ne modifie aucune ligne
du tenant source. Le tenant DEMO et ses données peuvent être supprimés ensuite
avec `demo:nettoyer`.

Une sauvegarde de la base reste recommandée avant d'exécuter le seeder, même
si celui-ci est limité au tenant DEMO.

```powershell
$backup = "E:\backup_ams_avant_demo999_$(Get-Date -Format 'yyyy-MM-dd_HH-mm').sql"
$mysqlUser = Read-Host "Compte MySQL"
mysqldump.exe --host=127.0.0.1 --user=$mysqlUser --password --result-file=$backup ams
if ($LASTEXITCODE -ne 0) { throw "La sauvegarde MySQL a échoué." }
```

## Procédure en 10 étapes

1. **Vérifier que le tenant de démonstration n'existe pas déjà.**

   ```powershell
   Set-Location E:\java-projects\ams
   php spark db:seed DemoAttendanceRules
   ```

   Le seeder refuse tout tenant id 999 ou slug `demo` qui n'est pas déjà
   marqué comme créé par ce seeder. Il copie les trois employés actifs avec le
   plus grand nombre de pointages non supprimés, avec 30 pointages par employé
   (90 au total). Il crée également le contrat, les créneaux, les paramètres et
   active l'opt-in sur le tenant DEMO seulement. Il est idempotent : le
   relancer synchronise les lignes portant ses marqueurs sans ajouter de
   doublons.

2. **Confirmer les données préparées.** Le seeder affiche les employés source,
   leur nombre de copies et la dernière date. Confirmer également les comptes
   et l'opt-in dans MySQL :

   ```sql
   SELECT id, name, slug, timezone, actif_regles_nouvelles
   FROM tenants WHERE id IN (1, 999);
   SELECT COUNT(*) AS employes FROM employes WHERE tenant_id = 999;
   SELECT COUNT(*) AS pointages FROM pointages WHERE tenant_id = 999 AND deleted_at IS NULL;
   SELECT COUNT(*) AS contrats FROM employe_contrats_horaire WHERE tenant_id = 999 AND deleted_at IS NULL;
   SELECT COUNT(*) AS creneaux_actifs
   FROM tenant_creneaux_pointage WHERE tenant_id = 999 AND actif = 1 AND deleted_at IS NULL;
   ```

   Résultat attendu après un seed complet : trois employés, 90 pointages,
   un contrat et quatre créneaux actifs. L'opt-in du tenant 1 doit garder sa
   valeur initiale.

3. **Démarrer le backend** dans un terminal :

   ```powershell
   Set-Location E:\java-projects\ams
   php spark serve --host 127.0.0.1 --port 8080
   ```

4. **Démarrer le frontend** dans un autre terminal :

   ```powershell
   Set-Location E:\java-projects\ams-frontend
   npm run dev
   ```

5. **Se connecter avec un compte du tenant DEMO.** Le seeder ne copie ni ne
   crée de compte utilisateur ou de mot de passe. L'application ne bascule pas
   automatiquement le tenant d'un utilisateur connecté; un compte de test
   autorisé pour le tenant 999 doit donc être provisionné séparément par le
   processus d'administration habituel. Ne réutilisez pas de mot de passe de
   production.

6. **Vérifier les paramètres.** Ouvrir
   `http://localhost:3000/parametres/pointages`. Confirmer que l'opt-in est
   activé et que les quatre créneaux sont présents :
   - ARRIVEE : `LIMITE_TARDIVE`, 07:30, tolérance 10 minutes;
   - DEBUT_PAUSE : `PLAGE`, de 12:00 à 14:00;
   - FIN_PAUSE : `LIMITE_TARDIVE`, 14:00, tolérance 10 minutes;
   - DEPART : `LIMITE_PRECOCE`, 17:30, tolérance 10 minutes.

   Le mode PLAGE affiche deux champs d'heure; les autres modes affichent
   l'heure cible. Les changements ne sont pas nécessaires pour le scénario
   préconfiguré.

7. **Vérifier les employés et le contrat.** Ouvrir `/employes`, rechercher les
   matricules préfixés `DEMO_`, puis ouvrir le premier employé (celui ayant le
   plus de pointages copiés). Dans l'onglet « Contrats horaires », vérifier le
   contrat TEMPS_PLEIN et ses dates. Les identifiants sont nouveaux et les
   pointages source restent dans le tenant 1.

8. **Comparer les pointages bruts.** Ouvrir `/pointages` et vérifier les lignes
   du jour choisi à l'étape suivante. L'échantillon initial diagnostiqué
   contient quatre événements complets le `2026-09-23` pour l'employé source
   #913 (CHECK_IN, BREAK_OUT, BREAK_IN, CHECK_OUT); cette journée est dans la
   période du contrat seedé. Le recalcul sélectionne la date ayant le plus de
   pointages copiés, en préférant le `2026-10-14` si cette date a quatre
   événements ou plus.

9. **Lancer le recalcul bout-en-bout.**

   ```powershell
   Set-Location E:\java-projects\ams
   php spark demo:bout-en-bout
   ```

   La commande affiche les pointages bruts de la journée, leurs types et
   statuts, puis les heures et statuts calculés pour chacun des quatre
   créneaux, le statut global et les heures travaillées. Sur l'échantillon
   présent lors du diagnostic, la journée du 23 septembre devrait donner quatre
   statuts `OK`, un statut global `PRESENT` et environ **8,47 heures
   travaillées**. Elle affiche ensuite la commande de nettoyage recommandée.

10. **Nettoyer après validation.** Contrôler le résumé des lignes affichées,
    puis confirmer `oui` :

    ```powershell
    php spark demo:nettoyer
    ```

    La commande supprime les lignes du tenant DEMO, les unités hiérarchiques,
    les employés et enfin le tenant 999 dans une transaction. Elle refuse de
    supprimer un tenant 999 qui ne porte pas exactement le marqueur du seeder.
    Pour ignorer la confirmation, utiliser `php spark demo:nettoyer --force`.
    Vérifier ensuite :

    ```sql
    SELECT id, name, actif_regles_nouvelles FROM tenants WHERE id IN (1, 999);
    SELECT COUNT(*) AS pointages_demo FROM pointages WHERE tenant_id = 999;
    ```

    Le tenant 999 ne doit plus exister; le tenant 1 et ses pointages doivent
    rester inchangés.

## Diagnostic effectué le 19 octobre 2026

- Tenant 1 : `OBR`, slug `obr`, fuseau `Africa/Kigali`.
- Tenant id 999 et slug `demo` : disponibles au moment du contrôle.
- Pointages non supprimés du tenant 1 : **85 045**, du `2000-01-01` au
  `2026-10-16`.
- Cinq premiers employés par nombre de pointages :

  | Employé source | Pointages | Première date | Dernière date |
  |---:|---:|---|---|
  | 913 | 1 568 | 2023-12-07 | 2026-10-02 |
  | 586 | 1 525 | 2023-12-07 | 2026-08-25 |
  | 600 | 1 498 | 2023-12-07 | 2026-09-25 |
  | 979 | 1 497 | 2023-12-07 | 2026-06-05 |
  | 453 | 1 433 | 2023-12-07 | 2026-09-25 |

- Les 30 plus récents de l'employé source #913 vont du `2026-09-10` au
  `2026-10-02`; le jour le plus riche dans ces copies est le `2026-09-23`,
  avec quatre pointages : 07:19:36, 12:05:10, 13:50:01 et 17:32:02.
- Les 30 plus récents des employés #586 et #600 couvrent respectivement le
  `2026-03-26`–`2026-08-25` et le `2026-07-16`–`2026-09-25`.
- Les index uniques de pointage sont tenant-scopés. Les clés composite des
  contrats et projections sont compatibles avec des employés créés dans le
  tenant DEMO. Les références de capteur des copies pointent vers les capteurs
  existants; elles ne modifient ni ne suppriment ces capteurs du tenant 1.

Les dates, comptes et candidats dépendent de la base au moment où le seeder
sera exécuté; le seeder recalcule les trois meilleurs employés et leurs
pointages récents au moment de chaque exécution.
