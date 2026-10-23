# Déploiement AMS

## État des artefacts

Le backend est une application CodeIgniter 4 dans `ams`; le frontend Next.js est dans le dépôt frère `ams-frontend`. Aucun `Dockerfile`, `docker-compose.yml` ou workflow CI/CD n’est présent dans ces dépôts au moment de cette documentation. Les commandes ci-dessous sont des recommandations à adapter, pas une procédure déjà validée sur un VPS.

## Préparer le serveur

1. Utilisez un serveur Linux supporté et un utilisateur système non privilégié pour exécuter PHP.
2. Installez PHP 8.2+ (8.4 recommandé), Composer, Node.js 20+, MySQL 8, Apache ou Nginx, et les extensions PHP nécessaires.
3. Configurez un nom DNS et un certificat TLS valide avant d’exposer le service.
4. Limitez les ports MySQL, LDAP, SMTP et GPIO au réseau privé; n’exposez que HTTPS côté client, et le port ADMS seulement si les appareils doivent joindre le serveur.
5. Utilisez des comptes techniques dédiés et des secrets différents par environnement.

## Configuration MySQL

Créez une base InnoDB et un utilisateur dédié avec les privilèges nécessaires aux migrations et à l’application. Ne réutilisez pas `root` en production.

```sql
CREATE DATABASE ams CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ams_app'@'10.%' IDENTIFIED BY '<secret-fort>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON ams.* TO 'ams_app'@'10.%';
FLUSH PRIVILEGES;
```

Restreignez le host du compte et les privilèges aux besoins réels après les migrations. Configurez la connexion dans `.env`; ne versionnez pas ce fichier.

## Déployer le backend

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php spark migrate
```

Définissez au minimum :

- `CI_ENVIRONMENT = production`;
- `app.baseURL` sur l’URL HTTPS publique de l’API;
- paramètres `database.default.*`;
- `JWT_SECRET` (secret aléatoire privé d’au moins 32 caractères) et `JWT_TTL`;
- paramètres LDAP, SMTP et GPIO uniquement lorsqu’ils sont nécessaires.

Rendez `writable/` accessible en écriture au processus PHP; le reste du code devrait être en lecture seule. La racine du virtual host doit pointer uniquement vers `backend/public`, pas vers la racine du dépôt. Désactivez l’affichage des erreurs en production et centralisez les logs avec des permissions restreintes.

## Apache / Nginx et HTTPS

Configurez le routage des URI vers `public/index.php` selon le guide officiel CodeIgniter et activez les règles de réécriture nécessaires. N’autorisez pas l’accès HTTP direct aux fichiers `.env`, `app/`, `vendor/`, `writable/` ou aux sauvegardes.

- Nginx : virtual host TLS, `root .../backend/public`, PHP-FPM, limite de taille d’upload alignée sur l’API.
- Apache : document root sur `backend/public`, `mod_rewrite` et PHP-FPM recommandés.
- HTTPS : certificat géré et renouvelé automatiquement; redirection HTTP vers HTTPS; protocoles TLS actuels.
- CORS : n’autorisez que les origines frontend connues. Les règles doivent rester cohérentes avec les cookies/jetons et les en-têtes Authorization.

Les fichiers de configuration du dépôt doivent être vérifiés avant leur emploi sur une distribution précise; aucun virtual host prêt à l’emploi n’est fourni.

## Déployer le frontend

Dans `ams-frontend`, configurez `NEXT_PUBLIC_API_URL` avec l’URL HTTPS de l’API avant le build :

```bash
npm ci
npm run build
npm run start
```

Placez Next.js derrière un reverse proxy TLS, ou utilisez l’adaptateur de déploiement choisi par l’infrastructure. Les variables `NEXT_PUBLIC_*` sont embarquées dans le bundle client; n’y mettez aucun secret. Vérifiez que les deux dépôts sont déployés sur des versions compatibles.

## Docker (à construire, non livré)

Avant d’ajouter Docker en production, créer et relire des images séparées backend/frontend, utiliser des images de base maintenues et non-root, injecter les secrets via le gestionnaire de secrets de l’hôte, persister `writable/` si nécessaire et placer MySQL sur un volume sauvegardé. Ne copiez ni `.env`, ni fichiers de données dans l’image.

Exemple d’orchestration conceptuel uniquement :

```text
reverse-proxy (TLS) -> frontend
reverse-proxy (TLS/API) -> backend PHP-FPM ou Apache
backend -> MySQL privé
```

Ne lancez pas de stack avec secrets fictifs en production. Documenter les health checks, limites de ressources, migrations contrôlées et stratégie de rollback dans les fichiers Docker qui seront effectivement ajoutés.

## Terminaux, LDAP et réseau

- Les terminaux ZKTeco doivent joindre une adresse serveur stable; en mode push, le chemin `/iclock` et le port configuré doivent être accessibles depuis leur réseau.
- Pour les réseaux distants, utilisez un VPN approuvé (par exemple NetBird) plutôt que d’exposer MySQL ou les appareils à Internet.
- Validez LDAP, SMTP et GPIO depuis le réseau réel avant d’activer les traitements planifiés.
- `php spark serve` est un serveur de développement et ne doit pas être utilisé comme service de production.

## Sauvegarde et restauration

Sauvegardez régulièrement MySQL avec chiffrement au repos et stockage hors serveur. Exemple d’export manuel (l’invite interactive/outil de secrets est préférable à une commande contenant le mot de passe) :

```bash
mysqldump --single-transaction --routines --triggers ams > ams-$(date +%F).sql
```

Restaurer dans une instance isolée avant de remplacer les données actives :

```bash
mysql ams < ams-AAAA-MM-JJ.sql
```

Documentez la rétention, chiffrez les sauvegardes, testez régulièrement la restauration et vérifiez les fichiers nécessaires (logos, données uploadées, configurations externalisées). Une sauvegarde non testée n’est pas une procédure de reprise.

## Checklist de mise en production

- [ ] Secrets et origines CORS relus; aucune valeur de développement en production.
- [ ] TLS, DNS et firewall vérifiés depuis les réseaux autorisés.
- [ ] Migration exécutée après sauvegarde et état `migrate:status` contrôlé.
- [ ] Super-admin initial créé/révélé selon une procédure sécurisée.
- [ ] Comptes de test, logs sensibles et données d’exemple absents.
- [ ] Login, droits cross-tenant, désactivation compte/tenant et sauvegarde/restauration vérifiés.
- [ ] LDAP, SMTP, GPIO et terminaux testés uniquement depuis le réseau autorisé.
- [ ] Logs, supervision et alertes opérationnels.
