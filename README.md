# AMS — Gestion des présences

AMS est une application de gestion des employés et de leurs présences, conçue pour plusieurs organisations (tenants). Le backend expose une API REST CodeIgniter 4 et reçoit les pointages des terminaux ZKTeco; le frontend est une application Next.js.

> Ce dépôt est le backend (`ams`). Le frontend est dans le dépôt frère `ams-frontend`. Les configurations de serveur et Docker sont des consignes de déploiement, pas des artefacts déjà fournis.

## Architecture

```mermaid
flowchart LR
  Browser[Navigateur / Next.js] -->|HTTPS, JSON, JWT| API[API CodeIgniter 4]
  Device[Terminaux ZKTeco / ADMS] -->|TCP/IP ou VPN| API
  API --> DB[(MySQL)]
  API --> Redis[(Redis Streams)]
  API --> LDAP[Active Directory / LDAP]
  API --> SMTP[Serveur SMTP]
  API --> GPIO[(Base GPIO source, optionnelle)]
```

| Composant | Technologies |
|---|---|
| Backend | PHP 8.2+ (PHP 8.4 recommandé), CodeIgniter 4.7, Composer |
| Frontend | Node.js 20+, Next.js 16, React 19, TypeScript |
| Base applicative | MySQL 8 / InnoDB |
| Terminaux | ZKTeco, accès direct ou push ADMS |

## Prérequis

- PHP 8.2+ et extensions `intl`, `mbstring`, `mysqli`/`mysqlnd`, `curl`, `json`, `openssl`, `fileinfo`, `gd` et `zip` selon les fonctionnalités activées.
- Composer 2.
- MySQL 8 et une base dédiée à AMS.
- Redis accessible depuis le backend pour la diffusion temps réel des pointages.
- Node.js 20+ et npm pour le frontend.
- Accès réseau aux services LDAP, SMTP, GPIO ou aux terminaux seulement si ces intégrations sont activées.

## Installation locale

### Backend

Depuis le répertoire `ams` :

```powershell
Copy-Item env .env
composer install
```

Configurez `.env` (voir [Configuration](#configuration)), puis appliquez les migrations et démarrez le serveur de développement :

```powershell
php spark migrate
php spark serve --host 127.0.0.1 --port 8080
```

L’API est alors disponible sur `http://127.0.0.1:8080`. Le serveur `spark serve` est destiné au développement, pas à la production.

### Frontend

Dans le dépôt frère `ams-frontend` :

```powershell
npm ci
Copy-Item .env.example .env.local
```

Renseignez `NEXT_PUBLIC_API_URL` dans `.env.local`, puis lancez :

```powershell
npm run dev
```

L’interface de développement Next.js est généralement disponible sur `http://localhost:3000`.

## Configuration

Le fichier `.env` du backend ne doit jamais être commité. Les clés sont des exemples; utilisez des secrets générés et un compte MySQL à privilèges minimaux.

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'

database.default.hostname = localhost
database.default.database = ams
database.default.username = ams_app
database.default.password = 'REMPLACER'
database.default.DBDriver = MySQLi
database.default.port = 3306

JWT_SECRET = 'REMPLACER_PAR_UN_SECRET_ALEATOIRE_D_AU_MOINS_32_CARACTERES'
JWT_TTL = 3600

REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379
REDIS_DATABASE = 0
```

Paramètres facultatifs selon l’installation :

- LDAP/Active Directory : hôtes, port, TLS, base DN et compte de recherche attendus par `LdapAuthService`.
- SMTP : hôte, port, chiffrement, identifiants et adresse d’expédition pour les rapports.
- GPIO : connexion de base source, à configurer séparément dans les fichiers de configuration appropriés.
- Temps réel : `REDIS_HOST`, `REDIS_PORT`, `REDIS_DATABASE` et, si nécessaire, `REDIS_PASSWORD`. Le serveur Redis doit être accessible par le backend.
- Frontend : `NEXT_PUBLIC_API_URL`, URL publique du backend; cette valeur est exposée au navigateur.

Ne mettez jamais de mots de passe, clés LDAP/SMTP ou données de production dans les fichiers d’exemple, les tests ou les captures.

## Commandes utiles

```powershell
php spark
php spark migrate:status
php spark migrate
php spark routes
php vendor/bin/phpunit --no-coverage tests/unit
php vendor/bin/phpunit --no-coverage tests
```

Les commandes applicatives incluent notamment :

| Commande | Usage |
|---|---|
| `auth:test-ldap {tenant_id}` | Tester la connexion LDAP du tenant |
| `auth:sync-ldap-users {tenant_id}` | Synchroniser les comptes LDAP |
| `auth:purge-logs` | Purger les anciens journaux d’authentification |
| `unites:importer {fichier}` | Importer la hiérarchie depuis CSV |
| `horaires:creer-defaut {tenant_id}` | Créer les horaires standards |
| `capteurs:sync-employes {capteur_id}` | Lire les utilisateurs du terminal |
| `capteurs:sync-pointages {capteur_id}` | Synchroniser les pointages |
| `capteurs:sync-users {capteur_id}` | Synchroniser les comptes du terminal |
| `capteurs:sync-attendance {capteur_id}` | Synchronisation historique des présences |
| `affectations:sync-capteur {capteur_id}` | Synchroniser les affectations vers le terminal |
| `capteurs:scan [capteur_id]` / `capteurs:scan-all` | Scanner un ou plusieurs terminaux |
| `rapports:envoyer-quotidiens` | Envoyer les rapports planifiés quotidiens |
| `rapports:envoyer-hebdomadaires` | Envoyer les rapports planifiés hebdomadaires |
| `rapports:envoyer-mensuels` | Envoyer les rapports planifiés mensuels |
| `adms:status`, `adms:diagnostic`, `adms:check-ready` | Diagnostiquer la réception ADMS |
| `pointages:recalculer-journee {date} --tenant=X [--employe=Y]` | Recalculer les projections quotidiennes |
| `demo:bout-en-bout` | Démonstration opt-in sur le tenant isolé DEMO |
| `demo:nettoyer [--force]` | Nettoyer les données du tenant DEMO après confirmation |

Consultez `php spark <commande> --help` avant d’exécuter une commande sur une installation réelle. Les commandes de synchronisation contactent des équipements ou systèmes externes.
Le seeder `php spark db:seed DemoAttendanceRules` crée le tenant isolé DEMO (id 999), y copie 90 pointages et prépare ses contrats/créneaux. Il ne modifie pas le tenant source 1.

Le rapport J-1 se configure dans **Rapports → Rapport automatique J-1** : l’heure par défaut est 08:00 et est évaluée dans le fuseau du tenant; les jours d’envoi suivent `tenants.jours_travailles`. Les mandats actifs reçoivent le rapport de leur unité et de ses sous-unités, et des destinataires supplémentaires peuvent être sélectionnés. Aucun envoi n’est effectué lorsque J-1 est un jour férié. Les envois utilisent le transport SMTP déjà configuré pour AMS et leur contenu est conservé dans l’historique de l’application. Le planificateur système doit exécuter régulièrement `php spark rapports:envoyer-quotidiens` à partir de l’heure configurée.

### Temps réel des pointages

Le scanner doit fonctionner pour interroger périodiquement les terminaux (par exemple `php spark capteurs:scan-all --interval=5`). À chaque nouveau pointage inséré et reclassé, le backend publie un événement dans le flux Redis propre au tenant. La latence comprend donc l’intervalle de scan et le temps de réponse du terminal; SSE ne peut pas recevoir un pointage que le scanner n’a pas encore collecté.

La page Pointages ouvre un flux SSE authentifié à `GET /api/realtime/pointages`, avec le JWT dans l’en-tête `Authorization` (pas dans l’URL). Le flux se ferme après environ 22 secondes puis reprend avec le dernier identifiant d’événement. Redis conserve approximativement les 2 000 événements récents de chaque tenant. Si Redis est indisponible, l’insertion en base reste réussie et l’échec de diffusion est journalisé.

Le curseur SSE est aussi conservé pendant la session navigateur afin de reprendre après une fermeture/reconnexion de la page. La synchronisation des capteurs utilise `dernier_pointage_sync` comme borne et déduplique les événements déjà enregistrés; après une indisponibilité du scanner, les pointages conservés sur le capteur sont récupérés au prochain scan, sous réserve que leurs horodatages soient postérieurs au dernier checkpoint.

L’action « Recalculer » sur un pointage appelle `POST /api/pointages/{id}/recalculer`. Elle affiche le résultat recalculé à partir des autres pointages du jour et de l’horaire applicable, mais ne modifie aucune ligne. Le formulaire de création manuelle utilise `POST /api/pointages`; les permissions de périmètre sont imposées côté backend.

Sur la page Pointages, CSV et Excel (.xlsx) exportent les filtres actifs. Les filtres s’appliquent dès leur changement; le type du graphique d’évolution est mémorisé dans les préférences du navigateur.

## Tests

- Tests unitaires/backend : `php vendor/bin/phpunit --no-coverage tests/unit`.
- Tests d’intégration API : voir [tests/e2e/README.md](./tests/e2e/README.md) et le script PowerShell. Ils créent un tenant temporaire; utilisez une base de test et un super-admin de test.
- Frontend : depuis `ams-frontend`, `npx tsc --noEmit`, `npm run lint` et `npm run build`.

## Documentation

- [Contrat API](./docs/API.md)
- [Schéma et migrations SQL](./docs/DATABASE.md)
- [Déploiement et opérations](./docs/DEPLOYMENT.md)
- [Diagnostic des capteurs](./docs/DIAGNOSTIC_CAPTEUR.md)
- [Validation manuelle des règles de pointage](./docs/VALIDATION_MANUELLE_POINTAGE.md)
- [Historique](./CHANGELOG.md)

## Sécurité et multi-tenant

Chaque utilisateur et chaque ligne métier appartient à un `tenant_id`. L’API obtient le tenant du JWT et doit maintenir le filtre tenant dans toutes les lectures et écritures. Les endpoints `api/admin/tenants` sont réservés au super-administrateur. Les mots de passe sont stockés sous forme de hash; les jetons expirent selon `JWT_TTL`. Le filtre JWT refuse les comptes et tenants inactifs.

Rôles applicatifs : `super_admin` (indicateur global), `admin`, `rh`, `superviseur`, `chef_service`, `directeur`, `commissaire`, `lecteur`. Les autorisations restent basées sur les rôles; un système générique de permissions granulaires n’est pas livré.

## Déploiement

Les instructions de production (HTTPS, Apache/Nginx, MySQL, sauvegardes et recommandations Docker) sont décrites dans [docs/DEPLOYMENT.md](./docs/DEPLOYMENT.md). Aucun Dockerfile/Compose ni pipeline CI/CD n’est actuellement livré dans ce dépôt.
