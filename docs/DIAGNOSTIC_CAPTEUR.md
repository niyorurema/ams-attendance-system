# Diagnostic ADMS du capteur physique

Ce guide sert à diagnostiquer le capteur ZKTeco/Nymbiar `10.100.28.230` lorsqu’aucune requête ADMS n’apparaît dans les logs CI4. Le serveur attendu est `10.100.27.136:8080`.

## 1. Vérifier le réseau depuis le capteur

Sur le terminal, ouvrir :

`Menu → Comm → Network Test → Ping`

Saisir `10.100.27.136` et lancer le test.

Résultat attendu : **4 paquets envoyés, 4 reçus, 0 % de perte**.

Si le ping échoue, vérifier le câble et le lien Ethernet, le VLAN, l’adresse IP et le masque du capteur, ainsi que sa passerelle. Les adresses du serveur (`10.100.27.136`) et du capteur (`10.100.28.230`) sont dans des sous-réseaux différents si le masque est `/24`; le routage entre les deux VLAN/sous-réseaux doit donc être autorisé. Un ping réussi ne prouve pas à lui seul que TCP 8080 est autorisé.

## 2. Vérifier la configuration Cloud Server / ADMS

Sur le capteur, ouvrir :

`Menu → Comm → Cloud Server Settings`

Selon la version, ce menu peut s’appeler `ADMS Settings`.

Vérifier ou saisir exactement :

| Paramètre | Valeur |
| --- | --- |
| Server Address | `10.100.27.136` |
| Server Port | `8080` |
| Server Path | `/iclock/` |
| Enable Domain Name | `No` |
| Proxy Server | `No` |

Pour `Server Address`, vérifier qu’il n’y a aucun espace avant ou après l’adresse. Le chemin doit avoir le slash initial et le slash final.

Enregistrer avec **SAVE**, puis redémarrer depuis `Menu → System → Restart`. Attendre environ 30 secondes après le redémarrage pour que le capteur rejoigne le réseau et commence ses demandes ADMS.

## 3. Vérifier le firmware

Ouvrir `Menu → System → Info` et relever la version du firmware. Le minimum prévu pour cette configuration ADMS est **6.60**. Si le firmware est antérieur, vérifier auprès du fournisseur du capteur la disponibilité d’une mise à jour compatible avec ce modèle avant de l’installer.

## 4. Vérifier les journaux du capteur

Ouvrir `Menu → System → Log` ou `Event Log`, selon le firmware. Chercher les entrées les plus récentes contenant des termes comme `ADMS`, `Server` ou `Connect`.

- `Server unreachable` : contrôler l’adresse du serveur, le routage, la passerelle, le VLAN et le chemin réseau.
- Erreur de connexion ou timeout : vérifier aussi que TCP 8080 est autorisé entre le réseau du capteur et le serveur.
- Aucune entrée récente : vérifier que le mode Cloud Server/ADMS est activé, enregistrer la configuration et redémarrer le terminal.

## 5. Observer les requêtes côté serveur

Dans un terminal du serveur, démarrer le suivi **avant** le redémarrage du capteur :

```powershell
php spark adms:watch --follow
```

Redémarrer ensuite le capteur et observer les nouvelles lignes pendant au moins 60 secondes. Le watcher affiche d’abord les dernières lignes ADMS existantes, puis suit les nouvelles entrées. Pour l’arrêter, utiliser `Ctrl+C`.

Les requêtes GET vers `/iclock/cdata` et `/iclock/getrequest` montrent que le capteur contacte le serveur. Un POST `table=ATTLOG` indique qu’il pousse les pointages. Les logs incluent l’adresse IP source; vérifier qu’elle correspond à `10.100.28.230`.

Si aucune nouvelle ligne n’apparaît, le capteur n’a pas atteint l’application pendant la période observée. Vérifier le ping, les paramètres ADMS, le routage entre sous-réseaux et le pare-feu Windows. Le contrôle local `php spark adms:server-status` ne teste pas à lui seul la connectivité depuis le capteur.

Pour lancer le diagnostic global après l’essai :

```powershell
php spark adms:diagnostic 1
```

## 6. Simuler une requête ADMS depuis Windows

Le script suivant teste les endpoints HTTP du serveur, envoie un pointage synthétique avec le badge `9999`, vérifie la ligne correspondante et nettoie uniquement cette ligne précise :

```powershell
.\scripts\test-adms-curl.ps1
```

Le script lit les paramètres de connexion MySQL depuis le `.env` local sans afficher le mot de passe. Il ne doit pas être lancé si un pointage métier réel utilise le badge `9999` à l’horodatage exact du test; le script vérifie ce cas avant l’envoi et refuse de continuer si la ligne existe déjà.

Ce test valide le serveur et le traitement HTTP depuis l’ordinateur qui l’exécute. Il ne remplace pas le test réseau effectué depuis le capteur physique.