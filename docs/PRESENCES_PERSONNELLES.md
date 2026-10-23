# Présences personnelles, fuseaux horaires et passkeys

## Fuseau horaire

Le fuseau de chaque tenant se règle dans **Paramètres → Configuration du tenant**. Les nouveaux tenants utilisent `Africa/Bujumbura`; l'heure affichée dans **Mon dossier** suit le fuseau du tenant et l'horloge se recale sur l'heure renvoyée par le serveur.

La migration `2026-10-14-000001` définit le fuseau par défaut et crée la table d'audit des pointages manuels. Elle ne doit être appliquée qu'après sauvegarde et vérification de la base cible.

## Passkeys WebAuthn

Le pointage personnel par l'interface exige une passkey. Pour éviter qu'une personne connectée avec des identifiants LDAP partagés enrôle sa propre passkey au nom d'un employé, un administrateur doit d'abord approuver l'enrôlement dans **Paramètres → Utilisateurs** après vérification de l'identité en personne. Cette autorisation ne sert qu'une fois et est consommée lors de l'enrôlement. L'utilisateur enrôle ensuite sa passkey depuis **Mon dossier**; chaque pointage déclenche une vérification WebAuthn avec validation utilisateur requise par l'authentificateur (biométrie ou PIN selon l'appareil). Le serveur vérifie le challenge à usage unique, sa durée de validité, l'origine, le RP ID, la signature et le compteur du credential. Le challenge expire après cinq minutes et ne peut être consommé qu'une fois.

Configurez ces variables dans l'environnement du backend avant d'activer la fonctionnalité :

```dotenv
WEBAUTHN_ORIGIN=https://ams.example.org
WEBAUTHN_RP_ID=ams.example.org
```

`WEBAUTHN_ORIGIN` doit être l'origine exacte du navigateur qui héberge l'application (schéma, hôte et port éventuel, sans chemin). `WEBAUTHN_RP_ID` doit être le domaine de cette origine ou un domaine parent valide. HTTPS est obligatoire en production; `localhost` est accepté pour le développement local. Appliquez ensuite la migration `2026-10-14-000002` qui crée les tables des credentials et des challenges.

La passkey renforce l'identification, mais ne prouve pas à elle seule qui tient physiquement l'appareil. Ne partagez pas l'appareil ni la passkey; une passkey synchronisée peut également être disponible sur d'autres appareils du même compte de plateforme.

## Audit réseau

Chaque pointage manuel conserve le compte, l'employé, le tenant, la date, le fuseau, la source d'authentification, l'adresse IP, le User-Agent et, pour le pointage personnel, l'empreinte SHA-256 du credential WebAuthn. Configurez uniquement les proxies de confiance dans CodeIgniter avant d'accepter les en-têtes transférant l'adresse IP.

Une page Web ne peut pas lire l'adresse MAC du client à travers HTTP. AMS ne l'enregistre donc pas et ne la déduit pas de l'adresse IP.
