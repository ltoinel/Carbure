---
title: "Tutoriel : installer Carbure sur YunoHost"
description: "Installer Carbure sur un serveur YunoHost avec le paquet carbure_ynh : domaine dédié, administrateur créé à l'installation, woob et synchronisation quotidienne inclus, sauvegardes YunoHost."
icon: material/server
---

# Tutoriel : Carbure sur YunoHost

Le paquet [`carbure_ynh`](https://github.com/ltoinel/carbure_ynh) installe Carbure sur un
serveur [YunoHost](https://yunohost.org), sans Docker. Tout se fait depuis l'interface
d'administration de YunoHost : pas d'assistant d'installation, la base de données, le
compte administrateur, woob et la synchronisation quotidienne sont préparés par le paquet.
Comptez une dizaine de minutes, dont quelques-unes pour construire woob.

!!! info "Ce qu'il vous faut"
    - Un serveur **YunoHost 12.1** ou plus, processeur **amd64** ou **arm64** (woob a
      besoin de `curl_cffi`, publié pour ces deux architectures seulement).
    - Environ **600 Mo** de disque (surtout l'environnement de woob) et 150 Mo de mémoire
      vive en fonctionnement (500 Mo pendant l'installation).
    - Un **domaine dédié** à Carbure (par exemple `budget.exemple.org`), ajouté dans
      YunoHost avec son certificat Let's Encrypt : Carbure ne s'installe pas dans un
      sous-chemin (`exemple.org/carbure`).
    - Un utilisateur YunoHost qui deviendra l'administrateur de Carbure.

!!! note "Hors du catalogue de YunoHost"
    Le paquet n'est pas encore dans le catalogue de YunoHost : il s'installe depuis son
    dépôt. Vos retours sur une mise à jour, une sauvegarde et sa restauration sont les
    bienvenus dans le [ticket #45](https://github.com/ltoinel/Carbure/issues/45).

## 1. Préparer le domaine

Dans l'administration de YunoHost : **Domaines → Ajouter un domaine**, saisissez par
exemple `budget.exemple.org`, puis, sur la page du domaine, **Certificat → Installer un
certificat Let's Encrypt**. Le domaine doit pointer vers votre serveur (enregistrement DNS
`A`, et `AAAA` en IPv6). Les applications mobiles exigent HTTPS.

## 2. Installer le paquet

Dans **Applications → Installer**, en bas de la page, **Installer une application
personnalisée**, avec l'adresse :

```text
https://github.com/ltoinel/carbure_ynh
```

L'installation pose quelques questions :

| Question | Réponse |
|---|---|
| **Domaine** | le domaine dédié de l'étape 1 |
| **Qui peut ouvrir le portail web** | `visitors` (tout le monde, Carbure demande sa propre connexion) ou un groupe d'utilisateurs YunoHost |
| **Langue** | celle de l'administrateur et des catégories de départ (français, anglais, espagnol, italien, allemand, portugais) |
| **Administrateur** | l'utilisateur YunoHost dont l'identifiant devient celui de l'administrateur de Carbure |
| **Mot de passe** | le mot de passe de cet administrateur dans Carbure (8 caractères minimum) |

En ligne de commande, la même installation :

```bash
sudo yunohost app install https://github.com/ltoinel/carbure_ynh
```

Le paquet installe PHP et MariaDB, crée la base, construit [woob](https://woob.tech) et les
paquets Python des modules bancaires dans un environnement virtuel, puis installe Carbure
avec ses catégories, ses règles et ses insights de départ.

!!! warning "Le mot de passe n'est pas celui de YunoHost"
    Carbure garde ses propres comptes, dont les applications mobiles ont besoin : il n'y a
    pas de connexion unique (SSO) avec YunoHost. L'**API** reste publique quel que soit
    le choix du portail (permission « api ») : les applications mobiles, la
    synchronisation quotidienne et les agents IA l'appellent en dehors du portail YunoHost,
    et Carbure vérifie ses propres jetons.

## 3. Se connecter

Ouvrez `https://budget.exemple.org/portal/` et connectez-vous avec l'identifiant de
l'utilisateur YunoHost choisi et le mot de passe saisi à l'installation. Protégez tout de
suite le compte : **Mon profil → Double authentification**.

Les autres membres du foyer s'ajoutent dans **Administration → Utilisateurs**, avec leur
propre mot de passe Carbure.

## 4. Ajouter vos comptes bancaires

Dans **Comptes**, le bouton **+** ajoute une banque : choisissez-la, saisissez vos
identifiants, puis cochez les comptes à suivre. Une banque qui demande une validation sur
le téléphone (authentification forte) se connecte et se synchronise depuis le portail.

Les identifiants bancaires restent sur votre serveur, dans le dossier de données de woob.
Une banque que woob ne prend pas en charge s'alimente par l'**import de relevés** (OFX,
QIF, CSV, CAMT.053) depuis l'onglet Transactions.

## 5. La synchronisation quotidienne

Le paquet planifie la synchronisation de tous les comptes une fois par jour, à une heure
tirée au hasard entre 3 h et 6 h (`/etc/cron.d/carbure`). Rien à configurer : le
**Planificateur** de Synology ou la `crontab` d'une installation Docker n'ont pas
d'équivalent à créer ici.

Quand une synchronisation échoue, mettez à jour les modules bancaires depuis la page
**Comptes** (**Mettre à jour les modules bancaires**) ; chaque mise à jour du paquet les met
aussi à jour.

## 6. Les applications mobiles et les notifications

Sur le téléphone, installez l'application
([iPhone](https://apps.apple.com/fr/app/carbure/id6824057363),
[Android](https://play.google.com/store/apps/details?id=org.carbure.app)), puis scannez le
QR code de **Mon profil → Mes appareils** : il donne l'adresse du serveur à l'application.

Les notifications push s'activent dans **Administration → Push mobile** (voir
[Notifications push](installation.md#notifications-push-carbure-push)) et les emails dans
**Administration → Paramètres**, section **Emails (SMTP)**.

## Où sont les fichiers ?

| Quoi | Où |
|---|---|
| Configuration (`prod.ini`, avec ses secrets) | `/home/yunohost.app/carbure/conf/` |
| Logs de Carbure | `/home/yunohost.app/carbure/logs/` |
| woob : modules bancaires et identifiants de vos banques | `/home/yunohost.app/carbure/woob/` |
| Code de Carbure et environnement virtuel de woob | `/var/www/carbure/` |

La plupart des réglages se modifient dans le portail, **Administration → Paramètres** :
inutile d'éditer `prod.ini`. Sur une deuxième instance, `carbure` devient `carbure__2`.

## Sécuriser l'installation

- **Portail réservé** : dans **Utilisateurs → Gérer les groupes et les permissions**,
  limitez la permission **Carbure (main)** aux membres du foyer. Laissez la permission
  **api** ouverte aux visiteurs : sans elle, les applications mobiles ne se connectent
  plus.
- **Double authentification** sur chaque compte Carbure, et sur le compte administrateur
  de YunoHost.
- **Mises à jour** de YunoHost et de Carbure (ci-dessous) appliquées régulièrement.
- **Sauvegardes chiffrées** si elles quittent le serveur : elles contiennent les
  identifiants bancaires.

Voir aussi la page [Sécurité](securite.md).

## Mettre à jour Carbure

Dans **Applications → Carbure**, le bouton **Mettre à jour** apparaît quand une nouvelle
version du paquet est publiée ; en ligne de commande :

```bash
sudo yunohost app upgrade carbure
```

YunoHost sauvegarde Carbure avant la mise à jour et restaure cette sauvegarde si elle
échoue. La mise à jour applique les migrations de la base de données (l'ancienne version
ne peut plus l'ouvrir ensuite), met à jour les modules bancaires et reconstruit woob quand
sa version change. La synchronisation est indisponible pendant quelques minutes. Lisez le
[changelog](changelog.md) avant de mettre à jour.

## Sauvegarder et restaurer

Les **sauvegardes YunoHost** (**Sauvegardes → Nouvelle sauvegarde**, ou
`sudo yunohost backup create --apps carbure`) contiennent la base de données et le dossier
de données (configuration, logs, woob). Une sauvegarde faite avec `--core-only` ne contient
pas le dossier de données : copiez alors `/home/yunohost.app/carbure` vous-même.

Pour restaurer : **Sauvegardes**, choisissez l'archive, puis **Restaurer**, ou
`sudo yunohost backup restore <archive> --apps carbure`.

Indépendamment de YunoHost, **Administration → Sauvegarde** exporte les données du foyer
dans un ZIP, réimportable sur une autre installation de Carbure (Docker, Synology…).

## Désinstaller

**Applications → Carbure → Désinstaller**, ou `sudo yunohost app remove carbure`. La base
et le dossier de données sont supprimés avec l'application : sauvegardez-les avant si vous
voulez garder vos données.
