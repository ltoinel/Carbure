# Tutoriel : Carbure sur un NAS Synology

Ce tutoriel installe Carbure sur un NAS Synology (DSM 7.2 ou plus) avec **Container
Manager**, en utilisant la base **MariaDB 10** du NAS. Tout se fait depuis l'interface de
DSM et le navigateur, sans aucune commande. Comptez une quinzaine de minutes.

!!! info "Ce qu'il vous faut"
    - Un NAS compatible avec Container Manager (la plupart des modèles « + » et récents,
      processeur Intel/AMD ou ARM 64 bits).
    - Un compte administrateur DSM.
    - L'adresse IP de votre NAS sur le réseau local (par exemple `192.168.1.10`), visible
      dans **Panneau de configuration → Réseau → Interface réseau**.

## 1. Installer les paquets

Dans le **Centre de paquets**, installez :

- **MariaDB 10** : la base de données ;
- **Container Manager** : pour faire tourner Carbure ;
- **phpMyAdmin** : pour créer la base de Carbure (il installe aussi Web Station).

## 2. Préparer MariaDB 10

Ouvrez l'application **MariaDB 10** :

1. Notez le **port** affiché (par défaut **3307** sur Synology).
2. Cochez **Activer la connexion TCP/IP**, puis **Appliquer**.
3. Si ce n'est pas déjà fait, définissez le mot de passe de l'utilisateur `root` de MariaDB
   (bouton **Modifier le mot de passe MariaDB**).

## 3. Créer la base et l'utilisateur de Carbure

Ouvrez **phpMyAdmin** (depuis le menu principal de DSM), connectez-vous avec `root` et le
mot de passe de MariaDB, puis :

1. Onglet **Comptes d'utilisateurs** → **Ajouter un compte d'utilisateur**.
2. **Nom d'utilisateur** : `carbure`.
3. **Nom d'hôte** : choisissez **N'importe quel hôte** (`%`) : Carbure se connecte depuis
   son conteneur, pas depuis le NAS lui-même.
4. **Mot de passe** : choisissez-en un solide et **notez-le**.
5. Cochez **Créer une base portant le même nom et donner à cet utilisateur tous les
   privilèges sur cette base**.
6. Cliquez sur **Exécuter** en bas de la page.

Vous avez maintenant une base `carbure` et un utilisateur `carbure`. Carbure créera ses
tables lui-même à l'étape 6.

## 4. Télécharger l'image Carbure

L'image est publiée sur [Docker Hub](https://hub.docker.com/r/ltoinel/carbure) : elle se
trouve directement depuis le DSM.

1. Ouvrez **Container Manager** → **Registre**.
2. Recherchez `carbure` et sélectionnez **ltoinel/carbure**.
3. Cliquez sur **Télécharger**, choisissez l'étiquette `latest` (ou une version, par exemple
   `1.0.0`) puis **Appliquer**. L'image apparaît dans **Image** une fois téléchargée.

## 5. Créer le projet Carbure dans Container Manager

1. Avec **File Station**, créez un dossier `carbure` dans le dossier partagé `docker`
   (créé par Container Manager).
2. Ouvrez **Container Manager** → **Projet** → **Créer**.
3. **Nom du projet** : `carbure` ; **Chemin** : le dossier `docker/carbure` ;
   **Source** : **Créer docker-compose.yml**.
4. Collez le contenu suivant en remplaçant l'adresse IP du NAS, le port de MariaDB et le
   mot de passe de l'étape 3 :

```yaml
services:
  carbure:
    image: ltoinel/carbure:latest
    ports:
      - "8080:80"
    environment:
      DB_HOST: 192.168.1.10        # adresse IP de votre NAS
      DB_PORT: 3307                # port de MariaDB 10 (étape 2)
      DB_NAME: carbure
      DB_USER: carbure
      DB_PASSWORD: mot-de-passe-de-l-etape-3
      LANGUAGE: fr
      SYNC_INTERVAL: 86400         # synchronisation bancaire une fois par jour
    volumes:
      - ./data:/data               # configuration, logs et banques de Carbure
    restart: unless-stopped
```

5. Cliquez sur **Suivant** (pas besoin de Web Station pour ce projet), puis **Terminé** :
   Container Manager démarre Carbure avec l'image de l'étape 4.

!!! tip "Port déjà utilisé ?"
    Si le port `8080` est déjà pris sur votre NAS, remplacez `"8080:80"` par exemple par
    `"8095:80"` et utilisez ce port dans la suite.

## 6. Installer Carbure depuis le navigateur

Ouvrez `http://192.168.1.10:8080/` (l'adresse de votre NAS) : l'**assistant
d'installation** s'affiche.

1. **Base de données** : tout est déjà rempli à partir du projet ; laissez le mot de passe
   vide (il vient du projet) et cliquez sur **Continuer**.
2. **Installation** : Carbure indique qu'il va créer ses tables. Choisissez l'identifiant et
   le mot de passe du **compte administrateur** (8 caractères minimum), puis **Installer**.
3. **Terminé** : connectez-vous avec ce compte.

Si l'assistant affiche une erreur de connexion à la base, vérifiez l'adresse IP, le port,
l'option **Activer la connexion TCP/IP** de MariaDB 10 et l'hôte `%` de l'utilisateur
`carbure`. Corrigez au besoin le projet (**Projet → carbure → Action → Arrêter**, modifier,
puis **Démarrer**).

## 7. Ajouter vos comptes bancaires

Dans le portail, onglet **Comptes** → **+** :

1. Choisissez votre banque dans la liste des banques supportées par
   [woob](https://woob.tech/) (par exemple BNP Paribas, Crédit Mutuel, Boursorama).
2. Saisissez les identifiants demandés et cliquez sur **Connecter la banque** : ils sont
   confiés à woob, qui les conserve dans le dossier `docker/carbure/data` du NAS ; Carbure
   ne les enregistre pas.
3. Cliquez sur **Suivre** pour chaque compte à synchroniser, puis **Enregistrer**.

!!! note "« Woob will not start as long as config file … is readable by group or other users »"
    woob refuse un fichier d'identifiants lisible par d'autres utilisateurs, ce qui arrive
    quand il est copié dans un dossier partagé du NAS. Carbure corrige ces droits au
    démarrage du conteneur et avant chaque appel à woob. Si le message persiste (ACL du
    dossier partagé), ouvrez **Container Manager** → **Conteneur** → `carbure` →
    **Action** → **Ouvrir le terminal** → **Créer** → `sh`, puis :
    `chmod 600 /data/woob/.config/woob/backends`

La synchronisation se lance compte par compte avec le bouton de synchronisation, et
automatiquement chaque jour grâce à `SYNC_INTERVAL`.

## 8. (Facultatif) Un accès HTTPS avec un nom de domaine

Pour utiliser l'application iOS ou accéder à Carbure depuis l'extérieur, passez par le
proxy inversé de DSM : **Panneau de configuration → Portail de connexion → Avancé → Proxy
inversé → Créer** :

- **Source** : protocole `HTTPS`, nom d'hôte `carbure.votre-domaine.fr`, port `443` ;
- **Destination** : protocole `HTTP`, nom d'hôte `localhost`, port `8080`.

Le certificat se crée dans **Panneau de configuration → Sécurité → Certificat** (Let's
Encrypt). La synchronisation est un flux continu : si elle s'interrompt après une minute
derrière le proxy, augmentez les délais d'expiration dans les paramètres avancés de la
règle.

!!! warning "Exposition sur Internet"
    Installez Carbure (étape 6) **avant** de l'ouvrir sur Internet. Derrière le proxy
    inversé de DSM, les requêtes semblent venir du NAS lui-même : l'assistant
    d'installation ne demanderait alors pas de code d'installation. Une fois Carbure
    installé, l'assistant disparaît.

## Mettre à jour Carbure

Dans **Container Manager** → **Image**, le badge **Mise à jour disponible** apparaît sur
`ltoinel/carbure` quand une nouvelle version est publiée : cliquez sur **Mettre à jour**
(ou téléchargez à nouveau l'étiquette `latest` depuis **Registre**). Le projet `carbure`
redémarre avec la nouvelle image ; sinon **Projet** → `carbure` → **Action** → **Construire**.
Au redémarrage, Carbure met sa base de données à jour automatiquement.

Pensez à sauvegarder la base avant une mise à jour : phpMyAdmin → base `carbure` →
**Exporter** → **Exécuter**, ou une tâche **Hyper Backup** incluant MariaDB 10.

## Sauvegarder

- **Base de données** : export phpMyAdmin ou Hyper Backup (application **MariaDB 10**).
- **Configuration et banques** : le dossier `docker/carbure/data` (Hyper Backup ou
  copie).
