# CONTEXTE DU PROJET

Je développe une application SaaS multi-tenant de gestion des présences/pointages 
d'employés utilisant des capteurs d'empreintes digitales ZKTeco/Nymbiar. 
L'application doit être accessible sur le web et interroger les capteurs via 
Internet (certains sur le réseau local, d'autres via NetBird VPN).

## STACK TECHNIQUE IMPOSÉE (institution)
- Backend : CodeIgniter 4 (PHP 8.4)
- Base de données : MySQL (migration depuis SQL Server existant)
- Bibliothèque capteurs : jmrashed/zkteco (v2.1.0) — DÉJÀ INSTALLÉE
- Authentification : firebase/php-jwt — DÉJÀ INSTALLÉE
- Frontend : React/Next.js (phase ultérieure)
- Réseau distant : NetBird (VPN WireGuard gratuit)
- Tâches temps réel : CI4 Tasks / CLI scripts

## ÉTAT ACTUEL DU PROJET
- Projet CI4 déjà créé dans E:\java-projects\ams
- Bibliothèque jmrashed/zkteco 2.1.0 installée et FONCTIONNELLE
- Connexion au capteur test 10.100.28.230:4370 → ✅ OK
- Lecture de 996 utilisateurs → ✅ OK (mais lent : 60s)
- Lecture des pointages → ❌ Retourne 0 (problème en cours de résolution)
- Encodage UTF-8 des noms cassé (Dieudonné → DieudonnÃÂ©)

## CAPTEUR CIBLE (test)
- IP : 10.100.28.230
- Port : 4370
- Firmware : Ver 6.60 Dec 11 2015
- Platform : ZMM100_TFT
- Serial : OIN6090056082901212
- 996 utilisateurs enregistrés
- Pointages présents mais non lus par la bibliothèque (bug connu UDP)

## STRUCTURE DES DONNÉES RETOURNÉES PAR getUser()
{
  "uid": 2,
  "userid": "10041",
  "name": "Dieudonné NDUWAYEZU",  (à corriger UTF-8)
  "role": 0,
  "password": "",
  "cardno": "0000000000"
}

## FONCTIONNALITÉS ATTENDUES

### Phase 1 (maintenant) : Fondations
1. Configuration MySQL dans .env
2. Migrations pour les tables :
   - tenants (multi-tenant)
   - users (utilisateurs de l'app)
   - capteurs (config des capteurs)
   - employes (employés avec badge_id)
   - pointages (historique)
   - sync_logs (traçabilité sync)
3. Modèles CI4 correspondants avec relations
4. Service ZKTecoService (wrapper propre autour de jmrashed/zkteco)
5. API REST avec JWT :
   - POST /api/auth/login
   - GET/POST/PUT/DELETE /api/capteurs
   - GET/POST/PUT/DELETE /api/employes
   - GET /api/pointages (avec filtres date/employé)
   - POST /api/capteurs/{id}/sync-users
   - POST /api/capteurs/{id}/sync-attendance
6. Tâches CLI (spark commands) :
   - php spark capteurs:scan-all (polling temps réel toutes les 5s)
   - php spark capteurs:sync-users (sync employés 1x/heure)
   - php spark capteurs:sync-attendance (sync historique 1x/jour)

### Phase 2 (plus tard)
- Multi-tenant complet (isolation par tenant_id)
- Frontend Next.js
- Migration SQL Server → MySQL
- Support NetBird VPN
- Temps réel via startEventMonitoring()

## CONTRAINTES IMPORTANTES
- Le code doit être en français pour les commentaires/docblocks
- Utiliser les conventions CI4 strictes (Namespaces, Models, Entities)
- Prévoir l'isolation multi-tenant dès le départ (colonne tenant_id)
- Le service ZKTecoService doit encapsuler TOUTE la logique capteur
- Ne jamais exposer les données brutes du capteur directement à l'API
- Toujours gérer les erreurs de connexion capteur proprement
- Timeout par défaut de 60s pour les lectures de capteur (c'est lent)

## PROBLÈME À RÉSOUDRE EN PRIORITÉ
La bibliothèque jmrashed/zkteco retourne 0 pointage alors qu'il y en a. 
Solutions à explorer :
1. Forcer le mode TCP au lieu d'UDP lors de la connexion
2. Utiliser getAttendanceWithLimit() ou getAttendanceByDateRange()
3. Passer au protocole Push HTTP (le capteur envoie les données)
4. Corriger la bibliothèque (fork)

## CE QUE JE VEUX QUE TU FASSES MAINTENANT
Commence par la Phase 1, étape par étape :

1. **Configuration MySQL** :
   - Modifier app/Config/Database.php
   - Créer le fichier .env avec les credentials
   - Configurer les paramètres UTF-8mb4

2. **Migrations** (une par une) :
   - Créer app/Database/Migrations/2026-09-25-000001_CreateTenantsTable.php
   - Créer app/Database/Migrations/2026-09-25-000002_CreateUsersTable.php
   - Créer app/Database/Migrations/2026-09-25-000003_CreateCapteursTable.php
   - Créer app/Database/Migrations/2026-09-25-000004_CreateEmployesTable.php
   - Créer app/Database/Migrations/2026-09-25-000005_CreatePointagesTable.php
   - Créer app/Database/Migrations/2026-09-25-000006_CreateSyncLogsTable.php

3. **Modèles CI4** :
   - app/Models/TenantModel.php
   - app/Models/UserModel.php
   - app/Models/CapteurModel.php
   - app/Models/EmployeModel.php
   - app/Models/PointageModel.php

4. **Service ZKTecoService** :
   - app/Services/ZKTecoService.php
   - Méthodes : connect(), getUsers(), getAttendance(), 
     getDeviceInfo(), syncEmployes(), syncPointages()

5. **Controller API de base** :
   - app/Controllers/Api/CapteursController.php
   - Avec endpoints CRUD et méthodes de sync

## FORMAT DE RÉPONSE ATTENDU
- Commence par la configuration MySQL et .env
- Ensuite, crée les migrations une par une avec explications
- Montre-moi le code complet à chaque étape
- Explique les choix techniques en français
- Signale-moi les décisions à prendre (ex: soft delete, timestamps, etc.)

Ne saute pas d'étapes. Je veux construire sur des bases solides.

Commence maintenant par la configuration MySQL.