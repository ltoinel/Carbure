---
title: "Changelog: the versions of Carbure"
description: "Every version of Carbure, from 1.0.0 to today: features, fixes and what to do when upgrading."
icon: material/history
---

# Changelog

The versions of Carbure, newest first. The details of each version (every pull request)
are in its [release notes on GitHub](https://github.com/ltoinel/Carbure/releases).

With Docker, upgrade with `docker compose pull && docker compose up -d` (see
[Updating](installation.md#mettre-a-jour)): the migrations of the database are applied when
the container starts, and the code works before them. On an existing web server, apply
them from the banner of the portal or with `php tools/migrate.php`. On YunoHost, the
upgrade of the package takes care of them (see
[YunoHost](yunohost.md#mettre-a-jour-carbure)). Back up the database before every upgrade.

## 1.11.0 — October 27, 2026 { #1110-27-octobre-2026 }

**Features**

- **Portal on a phone**: the everyday tabs move to a bar at the bottom of the screen, the
  others, the profile, the theme and the logout to its **More** menu; the header fits on
  one line.
- **Calendar** of the recurring transactions of the month in the Transactions tab.
- **Rules**: the **Keep the existing categories** setting (off by default) limits the rules
  to uncategorized transactions.
- **Data up to date without reloading**: every API response carries the versions of the
  data (`X-Carbure-Changes` header); the portal reloads the open tab when another member
  of the household or a synchronization changed what it shows.
- **Translated error messages**: the portal shows the errors of the API in the language of
  the user. The API adds a stable code (`error_code`, `params`) to its errors, without
  changing the existing fields (see [API](api.md#code-stable-dune-erreur)).
- **Devices**: a mobile app logged in without a notification token appears in **My
  profile → My devices**; its token is registered as soon as it gets one.
- **Published port**: `CARBURE_PORT` (in `docker/.env`) chooses the port of
  `docker/docker-compose.yml`, `8080` by default.
- The [YunoHost package](yunohost.md) is available.

**Fixes**

- **Banks**: banks asking for a validation from the first connection (Crédit Mutuel…)
  connect from the portal; the modules that needed missing Python packages (Banque
  Populaire, Caisse d'Épargne, Société Générale…) load; the choice of the Banque Populaire
  region is accepted. At the first bank, a warning reminds that the synchronization
  depends on the connectors of woob.
- Messages are shown above the dialogs; a switch refused by the API goes back to its
  position.

**Security**: nginx accepts 30 login attempts per minute and per client
(`/api/user/login`, `/api/user/login/mfa`, `/api/oauth/token`), then answers `429`.

**When upgrading**

- Two migrations: `2026-10-28_device_without_token` and `2026-10-29_data_change`.
- **Behind a reverse proxy**, the client address is read from `X-Forwarded-For` when the
  proxy has a private address (default): if your proxy has another address, set
  `TRUSTED_PROXIES`, otherwise the whole household shares the same login limit. After the
  upgrade, check that the logs show the addresses of the clients. See
  [Login rate limiting](securite.md#limitation-des-connexions).

## 1.10.0 — October 23, 2026 { #1100-23-octobre-2026 }

**Features**

- **Budget alerts** with a **global threshold** (every budget), which can be turned off,
  and **per-category thresholds** (**My profile → Notifications**).
- **End-of-month salary**: an incoming transfer dated **the 25th or later** counts for the
  next month (from the 26th before). The day is set with `next_month_day`
  (**Administration → Settings**, `0` never to move a transfer).
- **New version**: a banner tells the administrators when a version of Carbure is
  published. The server asks the public GitHub API for the latest version once a day,
  sending nothing about the instance; `update_check=false` turns it off.
- **Scripted installation**: `tools/install.php` installs Carbure without the web wizard
  (see [Run the wizard](installation.md#3-lancer-lassistant)); it is used by the
  [YunoHost package](yunohost.md).
- The mobile apps are published: App Store and Google Play links in **My profile → My
  devices**.

**When upgrading**

- Migration `2026-10-27_budget_alert_threshold`: the alert settings are kept; a user who
  watched a single budget keeps this budget, at its threshold, without a global threshold.
- The new salary day only applies to the transactions imported or synchronized after the
  upgrade.
- **First installation with Docker Compose**: `docker compose pull && docker compose up -d`
  uses the published image (without `pull`, Compose built the image from the clone).

## 1.9.1 — October 21, 2026 { #191-21-octobre-2026 }

**Security**: the image requires PHP 8.5.12, which fixes the PHP2Shell flaw. Upgrade
without waiting; no migration.

## 1.9.0 — October 20, 2026 { #190-20-octobre-2026 }

**Features**

- **Currency**: new optional `currency` setting (ISO 4217 code, `EUR` by default) in
  **Administration → Settings**, to show the amounts in another currency (no conversion).

**Fixes**

- **Synchronization**: each account starts with the list of the accounts of its bank
  (`woob bank list`) before its transactions; some modules (Crédit Mutuel) did not find
  the account without it. An account the bank no longer lists ends in error, with a clear
  message, instead of "Account not found".

**When upgrading**: no migration.

## 1.8.0 — October 18, 2026 { #180-18-octobre-2026 }

**Features**

- **Administration → Mobile push**: the registration of the instance with Carbure Push,
  its key and the status of the service.
- **My devices** also lists the web browsers logged in to the portal.
- A **QR code** in **My devices** opens the mobile apps on this server.

**Fixes**: an optional woob setting left empty is no longer written.

**When upgrading**

- Migration `2026-10-26_user_browser` (without it, no browser is listed).
- The `push_url` setting is removed: the notification service is always Carbure Push
  (`https://api.carbure.org`), a value left in `/data/conf/prod.ini` is ignored.
- The QR code gives the address of the API to the mobile apps: set `public_url`
  (**Administration → Settings**) if the portal is also opened through a local address.

## 1.7.0 — October 18, 2026 { #170-18-octobre-2026 }

**Features**: the [demo image](installation.md#essayer-carbure-demo) creates a `demo` /
`carbure` account and a random administrator password, and runs read-only, without
privileges (`docker/demo`).

**Security**

- The insight queries only read the budget tables, with a bounded run time.
- The bank credentials no longer go through the woob command line.

**When upgrading**: no migration.

## 1.6.0 — October 17, 2026 { #160-17-octobre-2026 }

**Features**

- **Demo image** with MariaDB and sample data.
- **Two-factor authentication** in its own profile tab, with the list of the trusted
  browsers.
- **Carbure Push**: `https://api.carbure.org` by default, and a consent asked before the
  registration.
- The categories move to **Administration**.
- The **API** tab tells when a reverse proxy blocks Swagger UI.

**Security**: personal data is no longer written to the server logs.

**When upgrading**: migration `2026-10-25_device_model` (model, system and app version of
each mobile device, unknown until its next connection). It also fixes the platform of the
Android devices already registered, recorded as iOS until then: their push notifications
work again.

## 1.5.0 — October 17, 2026 { #150-17-octobre-2026 }

**Features**

- **Two-factor authentication** (TOTP), which an administrator can require.
- **Backup and restore** of the household data in **Administration → Backup** (a ZIP; the
  import **replaces** the data of each imported type).
- **Languages**: Spanish, Italian, German and Portuguese.
- **Push notifications** through the Carbure Push service, for iOS and Android.
- Profile in tabs, with the choice of the budget watched by the alerts.
- API documentation (Swagger UI) in **Administration → API**.
- A "safety cushion" starter insight and the [insight recipes](insights.md).

**When upgrading**, **in this order**:

1. **Back up the database**.
2. **Push notifications**: 1.5.0 no longer sends the notifications to Apple (APNs) itself
   but goes through Carbure Push. Until the instance is registered there, **no push
   notification is sent** (everything else works normally). In 1.5.0, the default address
   of the service (`https://push.carbure.org`) is wrong: set `push_url` =
   `https://api.carbure.org` (the default from 1.6.0, setting removed in 1.8.0). Register
   the instance right after the upgrade (see
   [Push notifications](installation.md#notifications-push-carbure-push)), then remove the
   `[apns]` section and the `.p8` key from `/data/conf`.
3. **Update** the image. Three migrations: `2026-10-23_device_platform` (`ios` for the
   existing devices), `2026-10-23_user_budget_alert_category` (every budget watched for
   the existing users) and `2026-10-24_user_mfa` (empty tables).
4. **Check** that **Administration → API** shows Swagger UI (otherwise, the reverse proxy
   adds an `X-Frame-Options: DENY` header to remove for `/swagger/`), and that the
   large-expense threshold is in **My profile → Notifications**.

Before requiring two-factor authentication (`mfa_required`), set it up on your own account
and keep your recovery codes; if they are lost: `mfa_required=false` in
`/data/conf/prod.ini`. On an instance that is not in French, set the savings category of
the **Trends** (`savings_category`). The sample configuration no longer has the cleaning
rule `regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""`: keep it in your `prod.ini`
if it is there (removing it would create duplicates of card transactions).

## 1.4.0 — October 16, 2026 { #140-16-octobre-2026 }

**Features**

- **Statement import** for the main French banks and neobanks, one CSV profile per bank.
- **Email notifications**, the channel chosen by each user, and **budget alerts** when the
  spending reaches a threshold.
- Light or dark **theme**, system display preferences (high contrast, forced colors); the
  portal can be installed as an app.
- **Bank modules** updated from the **Accounts** page, the woob repository trusted by an
  administrator.
- Search of the transactions with a similar label, deletion of a transaction, followed
  accounts shown under their bank.

**Security**: no external resource (CDN) in the portal; the sync token in the URL is
deprecated (`X-Sync-Token` header).

**When upgrading**: five migrations (`2026-10-18_transaction_deleted`,
`2026-10-19_user_theme`, `2026-10-20_user_notification`,
`2026-10-21_user_budget_threshold`, `2026-10-22_budget_alert`). A scheduled task passing
the token in the URL (`?token=…`) still works: pass it in the `X-Sync-Token` header.

## 1.3.0 — October 13, 2026 { #130-13-octobre-2026 }

**Features**: `woob config update` at each start of the container; a readable name for
each connected bank; removal of a bank from the **Accounts** page; the history kept when a
synchronization fails, and a scheduled synchronization retried after a validation on the
phone.

**When upgrading**: migration `2026-10-17_bank_backend_label`.

## 1.2.0 — October 13, 2026 { #120-13-octobre-2026 }

**Features**: several logins of the same bank; names for the followed accounts; accounts
added in two steps, from those woob lists; synchronization of every account; copy of the
budgets of the previous month; woob debug logs in the synchronization log.

**Security**: the signature of the woob modules is checked (`gpgv`).

**When upgrading**: migration `2026-10-16_bank_account_label`.

## 1.1.0 — October 11, 2026 { #110-11-octobre-2026 }

**Features**: validation of the strong authentication of a bank from the portal;
statement import offered on an empty month; security policy.

**When upgrading**: `docker-compose.yml` can move MariaDB to a new major version (see
[Updating](installation.md#mettre-a-jour)).

## 1.0.5 — October 9, 2026 { #105-9-octobre-2026 }

**Features**

- **Recurring transactions**: a "Monthly" badge on the transactions of every month
  (salary, rent, subscriptions, bills), and the list of those not arrived yet this month.
- **Identical transactions kept**: two genuine identical transactions on the same day are
  no longer merged.
- **Simpler login**: the portal finds the API by itself.

**When upgrading**: `docker-compose.yml` moves to **MariaDB 13** (from 11). Back up the
database first; to stay on MariaDB 11, keep `image: mariadb:11`.

## 1.0.4 — October 6, 2026 { #104-6-octobre-2026 }

**Features**

- **Quick start**: the installation wizard offers about 30 categories, 100 rules and 13
  starter insights.
- The **bank of origin** in the detail of a transaction.
- The month and the year in the header, limited to the months that have transactions.
- **Logs** in JSON Lines, with the IP address of the client, and their retention
  (`log_retention_days`).

**When upgrading**: migration `2026-10-15_transaction_bank`. The existing logs are kept
forever until `log_retention_days` is set; a tool reading `data/logs/carbure_*.log` must
read the new format.

## 1.0.3 — October 5, 2026 { #103-5-octobre-2026 }

**Features**: **statement import** OFX/QFX, QIF, CSV and CAMT.053 with a preview of the
duplicates; a richer budget flow; **Settings** and **Sync** tabs in the administration;
search in the category picker.

**When upgrading**: no migration. Without Docker, allow 2 MB requests
(`client_max_body_size 2m;`) for the import.

## 1.0.2 — October 4, 2026 { #102-4-octobre-2026 }

**Features**: Claude connects to the MCP server with OAuth 2.1, without a token; budgets
per sub-category; **Administration** menu; `./start.sh` development stack.

**When upgrading**: migration `2026-10-14_oauth`. Behind your own nginx, route
`/.well-known/oauth-*` to `src/api.php` as `docker/nginx.conf` does.

## 1.0.1 — October 3, 2026 { #101-3-octobre-2026 }

Much lighter Docker image on Alpine; transaction icons in the colors of their category;
woob fixed on a NAS ("config file … is readable by group or other users").

## 1.0.0 — October 3, 2026 { #100-3-octobre-2026 }

First public version: transactions, budget and flow of the month, insights and trends,
categorization rules, bank synchronization by woob from the portal, MCP server for the AI
agents, administrator and user profiles, `amd64`/`arm64` Docker image with automatic
migrations.
