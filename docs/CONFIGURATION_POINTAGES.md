# Configuration et suivi des pointages

## Configuration par tenant

Les administrateurs et les ressources humaines configurent les pointages depuis **Paramètres → Configuration des pointages**. Les ressources humaines peuvent modifier les jours travaillés; seuls les administrateurs conservent l'accès aux réglages visuels du tenant.

- Les jours travaillés sont enregistrés dans la configuration du tenant.
- L'horaire par défaut s'applique lorsqu'aucun horaire individuel ni shift n'est affecté à l'employé. Les affectations existantes ne sont pas modifiées par la mise à jour de l'horaire par défaut.
- Chaque plage définit un type de pointage, une heure de début, une heure de fin et une tolérance en minutes.
- L'heure de référence est le début de la plage pour l'arrivée et le début de pause, et la fin de la plage pour la fin de pause et le départ.
- La tolérance d'une plage détermine à partir de combien de minutes de retard une arrivée ou une fin de pause est signalée. Les shifts conservent leur tolérance de calcul actuelle.

## Dossier personnel

La page **Mon dossier** affiche la situation de présence de la date sélectionnée et permet de revenir à aujourd'hui. Les chefs de service peuvent consulter les employés de leur unité; les directeurs, commissaires et superviseurs restent limités au périmètre organisationnel que l'API leur autorise. Les lecteurs ne peuvent rechercher que leur propre dossier.

Le pointage personnel n'accepte pas de date ni d'identifiant d'employé envoyés par le navigateur. Le serveur utilise l'employé lié au compte, l'heure courante du serveur et le prochain type de pointage attendu selon l'horaire applicable. Une passkey avec vérification utilisateur est requise. La saisie manuelle pour un autre employé est réservée aux administrateurs et aux ressources humaines; les autres rôles ne peuvent pas utiliser cette fonction pour pointer pour autrui. Voir [PRESENCES_PERSONNELLES.md](./PRESENCES_PERSONNELLES.md) pour la configuration des passkeys et les limites de l'audit.
