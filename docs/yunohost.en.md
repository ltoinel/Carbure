---
title: "Tutorial: install Carbure on YunoHost"
description: "Install Carbure on YunoHost with the carbure_ynh package: a domain of its own, woob and the daily synchronization included, YunoHost backups."
icon: material/server
---

# Tutorial: Carbure on YunoHost

The [`carbure_ynh`](https://github.com/ltoinel/carbure_ynh) package installs Carbure on a
[YunoHost](https://yunohost.org) server, without Docker. Everything is done from the
YunoHost administration interface: there is no installation wizard, the package prepares
the database, the administrator account, woob and the daily synchronization. It takes
about ten minutes, a few of them to build woob.

!!! info "What you need"
    - A **YunoHost 12.1** server or later, with an **amd64** or **arm64** processor (woob
      needs `curl_cffi`, published for these two architectures only).
    - About **600 MB** of disk (mostly the environment of woob) and 150 MB of memory when
      running (500 MB during the installation).
    - A **domain of its own** for Carbure (for instance `budget.example.org`), added in
      YunoHost with its Let's Encrypt certificate: Carbure cannot be installed in a
      sub-path (`example.org/carbure`).
    - A YunoHost user who will become the Carbure administrator.

!!! note "Not in the YunoHost catalog"
    The package is not in the YunoHost catalog yet: it is installed from its repository.
    Your feedback on an upgrade, a backup and its restoration is welcome in
    [issue #45](https://github.com/ltoinel/Carbure/issues/45).

## 1. Prepare the domain { #1-preparer-le-domaine }

In the YunoHost administration: **Domains → Add domain**, type for instance
`budget.example.org`, then, on the page of the domain, **Certificate → Install a Let's
Encrypt certificate**. The domain must point to your server (DNS `A` record, and `AAAA`
for IPv6). The mobile apps require HTTPS.

## 2. Install the package { #2-installer-le-paquet }

In **Applications → Install**, at the bottom of the page, **Install a custom app**, with
the address:

```text
https://github.com/ltoinel/carbure_ynh
```

The installation asks a few questions:

| Question | Answer |
|---|---|
| **Domain** | the domain of step 1 |
| **Who can open the web portal** | `visitors` (everyone, Carbure asks for its own login) or a group of YunoHost users |
| **Language** | that of the administrator and of the starter categories (French, English, Spanish, Italian, German, Portuguese) |
| **Administrator** | the YunoHost user whose login becomes the login of the Carbure administrator |
| **Password** | the password of this administrator in Carbure (8 characters at least) |

The same installation from the command line:

```bash
sudo yunohost app install https://github.com/ltoinel/carbure_ynh
```

The package installs PHP and MariaDB, creates the database, builds
[woob](https://woob.tech) and the Python packages of the bank modules in a virtual
environment, then installs Carbure with its starter categories, rules and insights.

!!! warning "The password is not the YunoHost one"
    Carbure keeps its own accounts, which the mobile apps need: there is no single
    sign-on (SSO) with YunoHost. The **API** stays public whatever the choice for the
    portal ("api" permission): the mobile apps, the daily synchronization and the AI
    agents reach it outside the YunoHost portal, and Carbure checks its own tokens.

## 3. Log in { #3-se-connecter }

Open `https://budget.example.org/portal/` and log in with the login of the chosen YunoHost
user and the password typed at the installation. Protect the account right away: **My
profile → Two-factor authentication**.

The other members of the household are added in **Administration → Users**, with their
own Carbure password.

## 4. Add your bank accounts { #4-ajouter-vos-comptes-bancaires }

In **Accounts**, the **+** button adds a bank: choose it, type your credentials, then tick
the accounts to follow. A bank asking for a validation on the phone (strong
authentication) is connected and synchronized from the portal.

The bank credentials stay on your server, in the data directory of woob. A bank woob does
not support is fed by the **import of statements** (OFX, QIF, CSV, CAMT.053) from the
Transactions tab.

## 5. The daily synchronization { #5-la-synchronisation-quotidienne }

The package schedules the synchronization of every account once a day, at a random time
between 3 and 6 a.m. (`/etc/cron.d/carbure`). Nothing to set up: unlike the Synology
**Task Scheduler** or the `crontab` of a Docker installation, there is no task to create
here.

When a synchronization fails, update the bank modules from the **Accounts** page (**Update
the bank modules**); every upgrade of the package updates them too.

## 6. The mobile apps and the notifications { #6-les-applications-mobiles-et-les-notifications }

On the phone, install the app
([iPhone](https://apps.apple.com/fr/app/carbure/id6824057363),
[Android](https://play.google.com/store/apps/details?id=org.carbure.app)), then scan the QR
code of **My profile → My devices**: it gives the address of the server to the app.

Push notifications are enabled in **Administration → Mobile push** (see
[Push notifications](installation.md#notifications-push-carbure-push)) and emails in
**Administration → Settings**, **Emails (SMTP)** section.

## Where are the files? { #ou-sont-les-fichiers }

| What | Where |
|---|---|
| Configuration (`prod.ini`, with its secrets) | `/home/yunohost.app/carbure/conf/` |
| Logs of Carbure | `/home/yunohost.app/carbure/logs/` |
| woob: bank modules and the credentials of your banks | `/home/yunohost.app/carbure/woob/` |
| Code of Carbure and virtual environment of woob | `/var/www/carbure/` |

Most settings are changed in the portal, **Administration → Settings**: no need to edit
`prod.ini`. On a second instance, `carbure` becomes `carbure__2`.

## Securing the installation { #securiser-linstallation }

- **Private portal**: in **Users → Manage groups and permissions**, limit the **Carbure
  (main)** permission to the members of the household. Leave the **api** permission open
  to visitors: without it, the mobile apps can no longer connect.
- **Two-factor authentication** on every Carbure account, and on the YunoHost
  administrator account.
- **Updates** of YunoHost and Carbure (below) applied regularly.
- **Encrypted backups** if they leave the server: they hold the bank credentials.

See also the [Security](securite.md) page.

## Updating Carbure { #mettre-a-jour-carbure }

In **Applications → Carbure**, the **Upgrade** button appears when a new version of the
package is published; from the command line:

```bash
sudo yunohost app upgrade carbure
```

YunoHost backs Carbure up before the upgrade and restores this backup if it fails. The
upgrade applies the migrations of the database (the old version can no longer open it
afterwards), updates the bank modules and rebuilds woob when its version changes. The
synchronization is unavailable for a few minutes. Read the [changelog](changelog.md)
before upgrading.

## Backing up and restoring { #sauvegarder-et-restaurer }

The **YunoHost backups** (**Backups → New backup**, or
`sudo yunohost backup create --apps carbure`) hold the database and the data directory
(configuration, logs, woob). A backup made with `--core-only` does not hold the data
directory: copy `/home/yunohost.app/carbure` yourself then.

To restore: **Backups**, choose the archive, then **Restore**, or
`sudo yunohost backup restore <archive> --apps carbure`.

Apart from YunoHost, **Administration → Backup** exports the data of the household to a
ZIP, which can be imported into another installation of Carbure (Docker, Synology…).

## Uninstalling { #desinstaller }

**Applications → Carbure → Uninstall**, or `sudo yunohost app remove carbure`. The database
and the data directory are deleted with the app: back them up first if you want to keep
your data.
