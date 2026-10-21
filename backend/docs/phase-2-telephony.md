# Phase 2 — Téléphonie Issabel : appels, enregistrements et diagnostic

Ce document décrit la seule intégration Issabel prise en charge : lecture du
CDR Asterisk/Issabel via une connexion MySQL dédiée **en lecture seule**, et
récupération ciblée des fichiers audio depuis un répertoire privé configuré.

L'intégration Kavkom (webhook CDR) n'est pas concernée par ce document.

## 1. Prérequis côté Issabel

### 1.1 Base CDR

La table standard `asteriskcdrdb.cdr` doit exposer au minimum
`calldate`, `src` et `dst`. Les colonnes suivantes sont utilisées lorsqu'elles
existent : `uniqueid`, `linkedid`, `duration`, `billsec`, `disposition`,
`recordingfile`. Aucune écriture n'est jamais effectuée.

Créer un compte MySQL strictement lecteur :

```sql
CREATE USER 'laravel_cdr'@'%' IDENTIFIED BY '<mot de passe>';
GRANT SELECT ON asteriskcdrdb.cdr TO 'laravel_cdr'@'%';
FLUSH PRIVILEGES;
```

Si l'agent et le sens sont stockés dans des colonnes propres à l'installation,
renseigner `ISSABEL_CDR_AGENT_COLUMN` et `ISSABEL_CDR_DIRECTION_COLUMN`.
Laisser vide quand la colonne n'existe pas : aucune valeur n'est inventée.

### 1.2 Répertoire des enregistrements

Le processus Laravel doit pouvoir **lire** les fichiers, jamais les modifier.
Monter le répertoire Issabel en lecture seule (NFS/SMB/bind mount) et pointer
`ISSABEL_RECORDING_ROOT` dessus, par exemple :

```
/mnt/issabel-recordings/2026/10/20/out-1234567890.1234.wav
```

`recordingfile` peut contenir :

- un chemin relatif (`2026/10/20/out-...wav`) : utilisé tel quel sous la racine ;
- un chemin absolu Issabel (`/var/spool/asterisk/monitor/2026/10/20/...`) :
  la partie datée est extraite et recherchée sous `ISSABEL_RECORDING_ROOT` ;
- un simple nom de fichier : la structure datée `AAAA/MM/JJ/nom` est déduite de
  la date de l'appel.

Aucun parcours récursif n'est effectué. Le chemin résolu est vérifié par
`realpath` : il doit rester strictement sous la racine configurée, sinon le
fichier est refusé (traversal, lien symbolique sortant).

L'audio récupéré est copié sous un nom opaque dans le disque privé
`TELEPHONY_RECORDING_DISK` (par défaut `local`, soit
`storage/app/private/recordings`). Le disque `public` est refusé.

## 2. Configuration

```dotenv
ISSABEL_ENABLED=true
ISSABEL_DB_HOST=10.0.0.10
ISSABEL_DB_PORT=3306
ISSABEL_DB_DATABASE=asteriskcdrdb
ISSABEL_DB_USERNAME=laravel_cdr
ISSABEL_DB_PASSWORD=...
ISSABEL_CDR_CONNECTION=issabel
ISSABEL_CDR_TABLE=cdr
ISSABEL_CDR_TIMEOUT=10
ISSABEL_CDR_AGENT_COLUMN=
ISSABEL_CDR_DIRECTION_COLUMN=
ISSABEL_RECORDING_ROOT=/mnt/issabel-recordings

TELEPHONY_RECORDING_DISK=local
TELEPHONY_RECORDING_PATH=recordings
TELEPHONY_RECORDING_MAX_BYTES=52428800
TELEPHONY_RECORDING_RETENTION_DAYS=180
TELEPHONY_RECORDING_PENDING_TIMEOUT=15
```

`ISSABEL_RECORDING_ROOT` vide signifie « récupération indisponible » : les
appels sont quand même listés avec l'état « référence signalée » et le
diagnostic explique la configuration manquante.

## 3. File d'attente et worker

La recherche d'appels et la récupération des enregistrements sont des jobs
Laravel (`RunCallSearchJob`, `RetrieveCallRecordingJob`). En production, la
file par défaut est `database` (`QUEUE_CONNECTION=database`) et **un worker
doit tourner en permanence** : sans lui, les lots restent « en attente » et les
enregistrements restent « en cours ».

Commande attendue :

```bash
php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=1
```

Exemple de service systemd :

```ini
[Unit]
Description=Laravel queue worker (telephony)
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/fiche-management/backend
ExecStart=/usr/bin/php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Après un déploiement, redémarrer le worker (`php artisan queue:restart`) pour
qu'il recharge le code.

Avec `QUEUE_CONNECTION=sync` (développement, tests), les jobs s'exécutent dans
la requête HTTP : l'interface affiche directement l'état final, sans worker.

## 4. Parcours utilisateur

1. Dans **Production**, cocher une ou plusieurs lignes.
2. Cliquer sur **Vérifier les appels**. Le panneau affiche immédiatement les
   appels déjà associés à ces lignes (lecture seule, aucun job), ainsi que le
   diagnostic Issabel sur demande.
3. Choisir la période puis **Lancer la recherche**. La case « Récupérer
   automatiquement les enregistrements disponibles » est cochée par défaut :
   chaque appel trouvé avec un fichier est mis en file automatiquement.
4. Le panneau suit le lot fournisseur par fournisseur (en attente, en cours,
   terminé, aucun appel, échec, non pris en charge).
5. Pour un enregistrement récupéré : **Écouter** (lecteur intégré, flux
   authentifié) ou **Télécharger**.
6. En cas d'échec : **Récupérer** relance une récupération (sauf si elle est
   déjà en cours depuis moins de `TELEPHONY_RECORDING_PENDING_TIMEOUT` minutes).

## 5. États enregistrement

| État | Signification |
| --- | --- |
| `unavailable` | Le CDR ne fournit aucun fichier pour cet appel. |
| `reference_only` | Un fichier est référencé mais pas encore récupéré. |
| `pending` | Récupération en file ou en cours. |
| `stored` | Fichier copié dans le stockage privé, lecture autorisée. |
| `missing` | Fichier absent du répertoire monté. |
| `failed` | Échec de copie/validation (taille, type MIME, disque). |
| `unsupported` | Récupération non configurée (racine absente) ou fournisseur non pris en charge. |
| `expired` | Fichier supprimé par la purge de rétention. |

Un échec n'est jamais converti en succès et un fichier déjà stocké n'est jamais
remplacé par un état plus faible.

## 6. Diagnostic

Interface : **Vérifier les appels → Diagnostic de connexion Issabel**.
API : `GET /api/telephony/diagnostics/issabel` (admin actif).
CLI :

```bash
php artisan telephony:diagnostics issabel
```

Le diagnostic vérifie la configuration, la connexion MySQL, la table, les
colonnes requises et optionnelles, le dernier CDR et le répertoire audio. Il
ne renvoie jamais d'identifiant, de mot de passe ni de chemin absolu.

Un répertoire qui existe mais répond `Input/output error` (partage NFS/SMB
déconnecté) est traité comme indisponible : remonter le partage, puis relancer
le diagnostic avant toute récupération.

## 7. Purge

`php artisan telephony:prune-recordings` supprime les fichiers audio expirés
selon `TELEPHONY_RECORDING_RETENTION_DAYS` et conserve les métadonnées d'appel.
Planifier la commande (le projet n'ajoute aucun cron automatique).

## 8. Tests

```bash
cd backend && php artisan test
```

Les tests utilisent une connexion CDR sqlite en mémoire, un fournisseur simulé
et des fichiers audio synthétiques : aucun serveur Issabel réel n'est requis.
