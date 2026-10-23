# Authentification AMS

## Modes disponibles

| Mode | Fonctionnement |
| --- | --- |
| `LOCAL` | Vérifie l’email et le hash du mot de passe stockés dans AMS. |
| `LDAP` | Authentifie l’utilisateur auprès de l’Active Directory configuré. |
| `HYBRIDE` | Tente LDAP, puis l’authentification locale si LDAP échoue. |

## Configuration actuelle du tenant OBR

Le tenant `1` est configuré en mode `HYBRIDE`. Dernier état agrégé observé des comptes liés à un employé :

| État | Nombre |
| --- | ---: |
| Avec email, source LDAP | 60 |
| Avec email, source LOCAL | 1 |
| Sans email, source LOCAL | 8 |

Ces comptes sont provisionnés dans AMS; cela ne garantit pas qu’un utilisateur puisse se connecter. Un compte LDAP nécessite une adresse correspondant à l’utilisateur AD et le mot de passe AD. Un compte LOCAL nécessite une adresse et un hash de mot de passe local. Un compte sans email et sans mot de passe ne peut pas se connecter.

Le compte administrateur local hors employé est distinct des nombres ci-dessus.

## Tester LDAP sans exposer de données personnelles

Vérifier d’abord la disponibilité des serveurs :

```powershell
php spark auth:test-ldap 1
```

Vérifier ensuite qu’une adresse de compte chef LDAP existe dans AD, sans afficher l’adresse ou son DN :

```powershell
php scripts/test-connexion-chef.php
```

Ce contrôle ne vérifie pas le mot de passe du chef. Pour tester l’authentification complète, celui-ci doit saisir lui-même son email et son mot de passe AD dans l’application; ne demandez et ne journalisez jamais son mot de passe.

## Comptes chef restés sans email

La liste détaillée, destinée à un terminal administrateur local, est disponible avec :

```powershell
php spark gpio:lister-emails-manquants --tenant=1
```

Le diagnostic recherche les correspondances AD actuelles et classe les résultats en ambigu, introuvable ou sans email utilisable. Il affiche le matricule, le nom et l’unité AMS, mais jamais l’email AD ou un mot de passe. Ces données nominatives restent confidentielles; ne copiez pas sa sortie dans des journaux partagés ou des tickets publics.

Les emails éventuels doivent être confirmés par l’administrateur AD ou l’intéressé avant toute mise à jour. Ne créez pas d’adresse fictive et ne passez pas un compte sans mot de passe vers une authentification locale en supposant qu’il pourra se connecter.
