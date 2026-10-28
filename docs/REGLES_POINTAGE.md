# Règles de pointage opt-in

## Services réutilisés

Le calcul reste porté par `CalculPresenceService`, qui réalise déjà le calcul
quotidien de présence. `HoraireService` reste la source des horaires historiques
et `ParametragePointageService` gère les paramètres et les créneaux par tenant.
Le calcul historique est conservé pour les tenants sans opt-in et pour ceux
qui n'ont pas encore configuré de nouveaux créneaux.

Lorsque le tenant a activé `actif_regles_nouvelles` et configuré ses créneaux,
la priorité des sources est :

```text
Shift > Contrat employé > Horaire individuel > Horaire par défaut du tenant
```

Les horaires historiques fournissent les heures de référence manquantes. Le
contrat peut adapter les heures de début et de fin. Un contrat `HORAIRE_LIBRE`
ou un jour non travaillé n'est pas jugé et ne crée pas de projection.

Les pointages sont associés à un créneau du même type dans la limite de
`ecart_max_association_minutes`. Les modes de créneau sont `LIMITE_TARDIVE`,
`LIMITE_PRECOCE` et `PLAGE`. La priorité du statut global est :

```text
ABSENT > HORS_CRENEAU > INCOMPLET > RETARD > ANTICIPE > ANORMAL > PRESENT
```

La valeur système par défaut de `ecart_max_association_minutes` est de 360
minutes. Elle limite l'association des arrivées anticipées, des départs
anticipés et des pointages proches de leur cible; une arrivée avant l'heure
attendue et un départ après l'heure attendue ne sont pas bornés par cette
limite. Une sortie du jour peut être associée jusqu'à l'heure de début du
service suivant, le lendemain. Les pointages de cette fenêtre qui ne sont pas
des `CHECK_OUT` ne sont pas absorbés dans la journée précédente.

Pour le profil OBR, les créneaux recommandés sont : arrivée à 07:30 avec
10 minutes de tolérance (une arrivée anticipée n'est pas pénalisée), départ en
pause à 12:00 avec `LIMITE_PRECOCE` et tolérance nulle, retour à 14:00 avec
`LIMITE_TARDIVE` et 10 minutes de tolérance, puis départ à 17:30 avec
`LIMITE_PRECOCE` et tolérance nulle. L'horaire OBR utilise les plages
00:00–07:30, 12:00–12:01, 14:00–14:01 et 17:30–23:59 comme plages de
référence; les plages d'une minute de pause sont un artifice de validation,
pas une tolérance supplémentaire. La tolérance de retour est indépendante de
la plage et de la durée maximale de pause.

La durée maximale de pause recommandée est de 130 minutes : une pause partant
à 12:00 et dont le retour est toléré jusqu'à 14:10 ne dépasse alors pas le
maximum. Le défaut système est 130 minutes. Les autres tenants ayant une valeur
enregistrée ne sont pas modifiés automatiquement.

Les shifts traversant minuit sont calculés sur la date de début du shift. Leur
sortie attendue est rattachée au lendemain et les pointages de la pause sont
déduits des heures travaillées lorsqu'ils forment une paire réelle. Le modèle
actuel des shifts ne contient pas d'heures de pause; les pauses des shifts ne
sont donc pas jugées selon les créneaux OBR de 12:00 et 14:00. Des règles de
pause propres à chaque shift nécessiteraient d'ajouter et de configurer ces
créneaux dans le modèle des shifts.

Les heures supplémentaires sont calculées après le seuil configuré. Le mode
`ARRONDI` arrondit au multiple le plus proche de
`pas_arrondi_heures_sup_minutes`; le mode `EXACT` conserve les minutes
calculées avant conversion en heures décimales.

## Configuration dans l'interface

Les administrateurs et RH configurent les paramètres et créneaux dans
`/parametres/pointages` de l'application AMS. Le super-administrateur, les
administrateurs et les RH peuvent activer ou désactiver les nouvelles règles.
Les superviseurs peuvent ouvrir cette page en lecture seule : les jours
travaillés, l'horaire classique et les nouvelles règles restent visibles, mais
tous les champs sont désactivés et les actions d'enregistrement sont masquées.

L'écran utilise les points d'accès JWT suivants :

```text
GET /api/admin/tenant/pointage-parametres
PUT /api/admin/tenant/pointage-parametres
```

Le GET est accessible au super-administrateur, aux administrateurs, aux RH et
aux superviseurs. Il retourne le commutateur opt-in, les paramètres avec leurs
valeurs, types et descriptions, ainsi que les créneaux actifs. Le PUT, réservé
au super-administrateur, aux administrateurs et aux RH, accepte un ou
plusieurs champs parmi :

```json
{
  "actif_regles_nouvelles": true,
  "parametres": {
    "mode_calcul_heures_sup": "ARRONDI",
    "pas_arrondi_heures_sup_minutes": 15
  },
  "creneaux": [
    {
      "type_creneau": "ARRIVEE",
      "mode": "LIMITE_TARDIVE",
      "heure_cible": "07:30",
      "tolerance_minutes": 10
    }
  ]
}
```

L'opt-in, les paramètres et les créneaux peuvent être modifiés par le
super-administrateur et les rôles de gestion autorisés (admin et RH). Les
superviseurs restent en lecture seule. Les créneaux transmis remplacent l'ensemble des
créneaux actifs du tenant; les entrées omises sont désactivées sans suppression
de leur historique.

Le paramètre `pas_arrondi_heures_sup_minutes` est initialisé à 15 minutes et
peut être modifié par ce point d'accès. Les valeurs doivent rester strictement
positives.

Lors du dernier contrôle de la base `ams` (2026-10-19), le tenant 1 avait
quatre créneaux enregistrés, tous inactifs, aucun contrat horaire et aucune
projection quotidienne. L'employé 1 avait 974 pointages, mais aucun depuis le
début du contrat de démonstration (`2026-10-01`). Son écart maximal
d'association enregistré est de 240 minutes; le nouveau défaut de 360 minutes
ne modifie pas cette valeur existante.

La migration `2026-10-15-000011_MigrerEcartMaxAssociationParDefaut` porte à
360 minutes les lignes actives restées exactement à l'ancien défaut de 240.
Elle conserve temporairement les identifiants et horodatages concernés afin que
son rollback ne restaure que les lignes toujours inchangées depuis son exécution;
une valeur modifiée ultérieurement n'est pas écrasée.

## Projection quotidienne

`CalculPresenceService::recalculerJournee()` persiste uniquement les journées
calculées par les nouvelles règles dans `pointages_journee`. Les calculs
historiques délégués et les jours sans jugement ne créent pas de projection.
`recalculerPlage()` accepte une date et des filtres optionnels de tenant et
d'employé; les paramètres et créneaux sont chargés une seule fois par tenant
pendant l'opération.

Pour recalculer une journée depuis la ligne de commande, utilisez :

```powershell
php spark pointages:recalculer-journee 2026-10-19 --tenant=1
php spark pointages:recalculer-journee 2026-10-19 --tenant=1 --employe=42
```

La date et l'identifiant `--tenant` sont obligatoires. `--employe` est un
filtre facultatif, limité au tenant indiqué. La commande traite les employés
actifs, compte les projections enregistrées, les journées ignorées (ancien
calcul ou absence de jugement) et les erreurs; elle retourne un code non nul si
une erreur survient. Chaque exécution recalcule les données, sans filtre de
fraîcheur.

Dans la fiche d'un employé, l'onglet « Contrats horaires » permet de consulter
les contrats et leur historique, ainsi que d'en créer un pour les profils
autorisés. Le superviseur conserve l'accès en lecture seule; l'API refuse
également ses opérations d'écriture.

Les tests d'intégration de la projection couvrent la présence à l'heure et le
retard, et les tests de clé étrangère vérifient l'isolation tenant/employé pour
les contrats et les projections. Ils doivent utiliser exclusivement la base de
test `ams_test` avec les migrations applicables.

## Démonstration manuelle

Le seeder `DemoAttendanceRules` crée le tenant isolé `DEMO` (id 999), y copie
les trois employés actifs du tenant 1 ayant le plus de pointages, ainsi que
leurs 30 pointages non supprimés les plus récents (90 lignes au total). Il
configure les quatre créneaux et crée un contrat TEMPS_PLEIN pour l'employé
ayant le plus de pointages. La période du contrat va de 30 jours avant sa
dernière date copiée jusqu'au lendemain inclus. Les jours sont stockés comme
`[1,2,3,4,5]` (lundi à vendredi). Le seeder est idempotent et active l'opt-in
uniquement sur le tenant DEMO; le tenant 1 n'est modifié qu'en lecture.

```powershell
php spark db:seed DemoAttendanceRules
php spark demo:bout-en-bout
php spark demo:nettoyer
```

Le seeder refuse d'utiliser l'id 999 ou le slug `demo` s'ils appartiennent déjà
à un tenant non marqué comme créé par lui. La commande de démonstration exige
le tenant, le contrat et les créneaux configurés; elle choisit le
`2026-10-14` s'il contient un pointage pour l'employé sous contrat, sinon sa
date la plus récente. Elle affiche les pointages bruts et leurs statuts face
aux valeurs calculées, sans modifier les pointages copiés. La commande de
nettoyage affiche le résumé des données puis exige une confirmation; `--force`
permet de l'ignorer. Elle ne supprime que le tenant 999 portant le marqueur
exact du seeder.

Le guide détaillé de validation navigateur, capteur et base se trouve dans
[VALIDATION_MANUELLE_POINTAGE.md](./VALIDATION_MANUELLE_POINTAGE.md).
