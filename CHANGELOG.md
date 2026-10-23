# Journal des modifications

Les versions taguées et les dates de publication n’étant pas établies dans ce dépôt, cet historique est maintenu par fonctionnalités documentées et par date de préparation (2026-10-04), et ne prétend pas représenter des releases versionnées.

## Non versionné — 2026-10-04

### Ajouté

- API multi-tenant de présence avec terminaux ZKTeco/ADMS, employés, unités, horaires et rapports.
- Authentification JWT locale et intégration LDAP/hybride.
- API et interface Next.js pour les employés, capteurs, pointages, rapports et paramètres.
- Gestion des utilisateurs, profil et changement de mot de passe.
- Administration des tenants par super-administrateur, incluant la création transactionnelle de son premier compte admin.
- Documentation API, base de données, déploiement et tests end-to-end API.

### Notes

- Les fonctionnalités dépendant du réseau OBR (LDAP, GPIO, terminaux et SMTP réels) doivent être validées dans ce réseau.
- Docker et CI/CD ne sont pas livrés à cette date.
- Les migrations présentes créent 14 tables métier; la table de migration CI4 est en plus.
