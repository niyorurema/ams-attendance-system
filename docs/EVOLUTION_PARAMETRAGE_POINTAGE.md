# Évolution du paramétrage des pointages

## Décisions finales

| # | Décision | Choix | Impact |
|---|---|---|---|
| A | Collation | Vérification avant et après migration | Commande `db:verifier-collation`; aucune migration ne force une collation différente |
| B | Contrats chevauchants | Contrôle applicatif | Préparé dans `ContratsHoraireService`; validation métier complète à ajouter avec l'API contrats |
| C | Cohérence multi-tenant | Clé étrangère composite | Index unique additif sur `employes(tenant_id, id)` puis FK composites pour contrats et projection |
| D | `statut_global` | Ajout de `INCOMPLET`, `ANTICIPE` et `ANORMAL` | L'ENUM de la projection peut représenter tous les statuts agrégés |
| E | Nouveaux tenants | Hook et valeurs de repli | Initialisation automatique après insertion, seeder réutilisant le même service |

Priorité d'agrégation définie pour l'interpréteur à venir :

```text
ABSENT > HORS_CRENEAU > INCOMPLET > RETARD > ANTICIPE > ANORMAL > PRESENT
```

## Changements de schéma préparés

Les changements sont additifs. Les migrations `000003`, `000004` et `000007`
restent inchangées. Les migrations contrats et projection sont renommées avant
leur première exécution afin que l'index référencé soit créé en premier.

Ordre prévu :

1. `2026-10-14-000003_AddNewAttendanceRulesOptInToTenants.php`
2. `2026-10-14-000004_CreateTenantCreneauxPointageTable.php`
3. `2026-10-14-000007_CreateTenantParametresPointageTable.php`
4. `2026-10-14-000008_AddUniqueTenantIdToEmployes.php`
5. `2026-10-14-000009_CreateEmployeContratsHoraireTable.php`
6. `2026-10-14-000010_CreatePointagesJourneeTable.php`

`000008` vérifie si son index existe déjà avant de le créer. Les contrats et la
projection conservent leur clé étrangère vers le tenant et utilisent en plus
une clé étrangère composite `(tenant_id, employe_id)` vers
`employes(tenant_id, id)`. La table existante `pointages` n'est pas modifiée;
sa cohérence multi-tenant reste un point à traiter séparément.

Les créneaux et paramètres uniques actifs utilisent une colonne générée qui
vaut `NULL` après soft delete. Ainsi, MySQL garantit l'unicité des entrées
actives et conserve les lignes supprimées. Les paramètres sont initialisés par
`ParametragePointageService`, qui ne remplace aucune valeur existante et fournit
des valeurs système de repli si la table ou la clé manque.

## Collation

La commande `php spark db:verifier-collation` affiche les jeux de caractères et
collations de la base et de la connexion, ainsi que la collation des nouvelles
tables lorsqu'elles existent. Elle retourne un code non nul si une collation
diffère de `utf8mb4_unicode_ci`.

Diagnostic exécuté avant migration le 14 octobre 2026 :

- Base : `utf8mb4` / `utf8mb4_unicode_ci`
- Connexion initiale : `utf8mb4` / `utf8mb4_0900_ai_ci`
- Tables nouvelles : absentes

La valeur `database.default.DBCollat` était déjà présente dans `.env`, mais le
pilote MySQLi de CodeIgniter ne l'appliquait pas à la collation de session. Un
adaptateur de connexion applicatif applique désormais cette collation à chaque
connexion. Le diagnostic suivant confirme `utf8mb4_unicode_ci` pour la base et
la session; les nouvelles tables sont toujours absentes. Aucun changement de
collation serveur ni migration n'a été exécuté.

## Droits et règles métier à venir

L'opt-in demeure à `0` par défaut et les anciennes plages ne sont pas converties.
La projection quotidienne reste un cache recalculable; les pointages bruts sont
la source de vérité. Le superviseur conserve des droits de lecture seule. Le
contrôle des chevauchements de contrats est prévu côté service/API et n'est pas
encore garanti par une contrainte de base.
