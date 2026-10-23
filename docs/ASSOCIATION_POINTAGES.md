# Association des pointages

## Règle

L’identifiant utilisateur fourni dans `raw_data.user_id` est le matricule et
constitue la correspondance prioritaire avec `employes.matricule`. Le
`badge_id` est l’identifiant numérique reçu du capteur; il peut être différent
du badge enregistré pour l’employé et ne doit servir que de solution de repli.

Les recherches sont toujours limitées au tenant du capteur.

## Synchronisations

Les synchronisations Pull (`ZKTecoService::syncPointages()`) et ADMS
(`IclockController`) cherchent d’abord l’employé par matricule, puis par
`badge_id` si le matricule ne correspond à aucun employé. Les valeurs
`user_id`, `badge_id`, `matricule` reconnu et `user_id_capteur` sont conservées
dans `raw_data`. Après insertion et reclassification, les deux voies publient
également les nouveaux pointages dans Redis pour le flux SSE.

## Diagnostic du flux

Le flux utilise Redis Streams (`XADD` / `XREAD`), pas Redis Pub/Sub. Pour
observer sa taille avant et après un nouveau pointage :

```powershell
docker exec flet-manager-redis redis-cli XLEN ams:tenant:1:pointages
```

Les journaux backend tracent désormais les insertions Pull, les identifiants
des entrées ajoutées par `XADD` et les événements lus par le contrôleur SSE.
Le frontend trace aussi chaque ligne `data:` et l’événement décodé. Ces traces
ne consignent ni nom ni matricule d’employé.

Le flux SSE HTTP est volontairement borné à 22 secondes afin de libérer
régulièrement les workers PHP. Une fermeture après environ 22 secondes suivie
d’une reconnexion avec `Last-Event-ID` est attendue; elle ne signifie pas que
le flux Redis s’est arrêté. Le serveur envoie un commentaire heartbeat toutes
les 10 secondes et Redis bloque au maximum 5 secondes par lecture.

## Lecture incrémentale du capteur

Le protocole ZKTeco ne filtre pas son buffer ATTLOG par date. Le client utilise
donc les offsets du buffer et le champ `capteurs.dernier_pointage_index` pour
reprendre à proximité de la dernière position lue, au lieu de télécharger tout
le journal à chaque scan. Une fenêtre de recouvrement de 100 enregistrements
permet de récupérer les nouveaux pointages si le capteur a atteint sa capacité
et que le compteur ne progresse plus; les doublons restent éliminés par la clé
existante. Si le nombre d’enregistrements baisse après une remise à zéro du
capteur, la lecture repart du début afin de ne pas sauter ses entrées.
La position du buffer est le curseur incrémental; les horodatages des nouvelles
entrées ne sont pas filtrés par date, afin de ne pas perdre un pointage dont
l’horloge capteur serait en retard.

La colonne `dernier_pointage_index` est ajoutée par migration. Elle reste
`NULL` jusqu’à la première synchronisation complète après la migration; cette
première lecture est intégrale par prudence. Les scans suivants repartent de
l’index sauvegardé. Les synchronisations limitées (`--limit`) ne déplacent pas
le curseur. Appliquer d’abord la migration dans chaque environnement avec
`php spark migrate`.

## Correction des pointages orphelins

La commande suivante examine uniquement les pointages du tenant dont
`employe_id` est `NULL`. Elle fonctionne en simulation par défaut :

```bash
php spark pointages:associer-par-matricule --tenant=1
```

Après vérification du rapport, appliquer les associations avec :

```bash
php spark pointages:associer-par-matricule --tenant=1 --apply
```

La commande associe uniquement les enregistrements dont
`raw_data.user_id` correspond sans ambiguïté au matricule d’un employé du même
tenant. Elle ne modifie pas les pointages déjà associés.

## Index

La migration de création d’`employes` ajoute déjà une clé unique sur
`(tenant_id, matricule)`. Elle accélère les recherches par tenant et matricule;
une migration d’index supplémentaire ferait donc doublon.
