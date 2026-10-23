# Tests end-to-end AMS

`Invoke-AmsE2E.ps1` exerce l’API HTTP réelle : connexion super-admin, création d’un tenant temporaire et de son administrateur, connexion à ce tenant, création d’un employé et d’un utilisateur, génération du rapport quotidien, puis désactivation/nettoyage du tenant.

## Prérequis

- PowerShell 7+.
- Backend démarré et migrations appliquées.
- Base **de test dédiée**; le script crée et supprime un tenant et un compte.
- Identifiants d’un super-admin non-production fournis par variables d’environnement.

```powershell
$env:AMS_E2E_BASE_URL = 'http://127.0.0.1:8080'
$env:AMS_E2E_SUPERADMIN_EMAIL = 'superadmin@example.test'
$env:AMS_E2E_SUPERADMIN_PASSWORD = Read-Host 'Mot de passe du super-admin de test'
.\tests\e2e\Invoke-AmsE2E.ps1
Remove-Item Env:AMS_E2E_SUPERADMIN_PASSWORD
```

Le mot de passe ne doit pas être saisi comme argument de commande ni commité dans un fichier. Fermez la session PowerShell ou effacez la variable après le test. N’utilisez jamais un super-admin de production.

Le script affiche un résumé des contrôles, mais ne journalise pas le jeton ni le mot de passe généré. Son bloc `finally` désactive le tenant temporaire via l’API. En cas d’interruption machine/réseau, recherchez le préfixe `ams-e2e-` dans l’interface admin et désactivez les ressources de test après vérification.

## Portée

Il s’agit d’un test d’intégration API en processus PowerShell, pas d’un test navigateur Playwright/Cypress. Les appels réels LDAP, GPIO, SMTP et appareils ne sont pas déclenchés; ils nécessitent un environnement réseau dédié.
