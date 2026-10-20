# Phase 2 — Intégration téléphonie (Kavkom / Issabel)

Cette phase ajoute la recherche d'appels pour les lignes de production, le
stockage de métadonnées et la récupération d'enregistrements **privés**. Elle
ne transcrit rien et ne décide jamais qu'un contrat est validé.

> **État de vérification :** les adaptateurs sont implémentés et testés avec
> des fournisseurs simulés. **Aucun appel réel n'a été effectué** dans cet
> environnement : aucun identifiant Kavkom ni accès Issabel n'était disponible.
> Les points à confirmer sur les systèmes réels sont listés plus bas.

## 1. Capacités réellement documentées

### Kavkom — vérifié dans la documentation officielle (octobre 2026)

Documentation consultée : <https://help.kavkom.com/en/api-integration/kavkom-api/>
et <https://help.kavkom.com/en/integration-api/webhook-phone-system/>.

| Capacité | État | Preuve / limite |
|---|---|---|
| Recherche d'historique d'appels par API | **Non prise en charge** | L'API PBX documentée expose `extension/list`, `extension/list_limited`, `active_call/call` et `active_call/kill`. Aucun endpoint de recherche d'historique n'est documenté. |
| Réception des CDR | **Prise en charge (webhook)** | Le webhook « Mapping for CDR » transmet `xml_cdr_uuid`, `direction`, `status`, `duration`, `billsec`, `outside_number`, `destination_number`, `caller_id_number`, `referenced_extension`, `start_epoch`, `end_epoch`, `recording_url`, etc. |
| Téléchargement du fichier audio | **Non pris en charge** | Le webhook ne transmet pas le fichier, seulement `recording_url` lorsque disponible. Aucun endpoint de téléchargement n'est documenté. L'URL n'est pas stockée (elle peut être signée/expirante). |
| Recherche par numéro normalisé | **Non prise en charge** | Découle de l'absence d'API d'historique. L'interface affiche un état explicite, jamais « aucun appel ». |

Conséquence : l'adaptateur Kavkom **ingère** les CDR poussés par webhook et les
rapproche des lignes de production. Une recherche Kavkom pour une ligne
sélectionnée retourne l'état `unsupported` avec un message actionnable.

### Issabel — méthode retenue, à confirmer sur l'installation

Documentation consultée : <https://ccmanual.issabel.com/en/api/issabel-api.html>
(API REST orientée extensions/ring groups ; pas de CDR documenté).

L'intégration implémentée repose sur :

1. une **connexion MySQL dédiée en lecture seule** vers la base CDR Asterisk
   (`asteriskcdrdb`, table `cdr` par défaut) ;
2. la lecture des colonnes standard `calldate`, `src`, `dst`, `uniqueid`, et
   des colonnes optionnelles `linkedid`, `duration`, `billsec`,
   `disposition`, `recordingfile` détectées à l'exécution ;
3. si `ISSABEL_RECORDING_ROOT` est configuré, la copie du fichier audio depuis
   un répertoire privé accessible par le serveur Laravel vers le stockage privé.

**Non vérifié :** le schéma exact de la base déployée, la présence de
`recordingfile`, le format des numéros, et le chemin réel des enregistrements.
Ces points doivent être validés avec le responsable Issabel avant production.
Aucune écriture, aucun `UPDATE`/`DELETE` n'est exécuté sur le PBX.

## 2. Configuration

Toutes les valeurs sont lues dans l'environnement. Ne jamais versionner de
token, mot de passe ou clé privée.

```dotenv
# Kavkom
KAVKOM_WEBHOOK_ENABLED=false
KAVKOM_WEBHOOK_SECRET=
KAVKOM_WEBHOOK_HEADER="X-Kavkom-Webhook-Token"

# Issabel (compte MySQL restreint à SELECT)
ISSABEL_ENABLED=false
ISSABEL_DB_HOST=127.0.0.1
ISSABEL_DB_PORT=3306
ISSABEL_DB_DATABASE=asteriskcdrdb
ISSABEL_DB_USERNAME=
ISSABEL_DB_PASSWORD=
ISSABEL_CDR_CONNECTION=issabel
ISSABEL_CDR_TABLE=cdr
ISSABEL_CDR_TIMEOUT=10
ISSABEL_CDR_AGENT_COLUMN=
ISSABEL_CDR_DIRECTION_COLUMN=
ISSABEL_RECORDING_ROOT=

# Politique et stockage
TELEPHONY_SEARCH_DEFAULT_DAYS=30
TELEPHONY_SEARCH_MAX_DAYS=366
TELEPHONY_SEARCH_MAX_RECORDS=50
TELEPHONY_SEARCH_MAX_RESULTS=200
TELEPHONY_RECORDING_DISK=local
TELEPHONY_RECORDING_PATH=recordings
TELEPHONY_RECORDING_MAX_BYTES=52428800
TELEPHONY_RECORDING_RETENTION_DAYS=180
```

Après modification : `php artisan config:clear` (ou `config:cache` en
production). Les migrations ajoutent uniquement `call_searches`,
`call_search_items` et `call_records`.

## 3. Architecture

| Composant | Rôle |
|---|---|
| `CallProvider` | Contrat indépendant : nom, capacités, recherche bornée. |
| `KavkomCallProvider` | Déclare la recherche non prise en charge ; les CDR arrivent par webhook. |
| `IssabelCallProvider` | Mappe les lignes CDR en appels normalisés. |
| `IssabelCdrRepository` | Requête MySQL en lecture seule, colonnes détectées, erreurs typées. |
| `CallSearchService` | Valide une sélection, crée un lot et dispatche un job par ligne × fournisseur. |
| `RunCallSearchJob` | Recherche un fournisseur, persiste les appels, gère les états et les retries bornés. |
| `CallPersister` | Écriture idempotente sur `(provider, provider_call_id)`. |
| `ProductionPhoneMatcher` | Rapprochement conservateur par E.164, refus des doublons ambigus. |
| `KavkomWebhookController` | Ingestion CDR authentifiée par secret partagé. |
| `IssabelRecordingFetcher` | Copie un fichier depuis le répertoire privé, avec protection path traversal. |
| `RecordingStorage` | Écriture atomique, type MIME et taille vérifiés, nom opaque. |
| `CallRecordingController` | Téléchargement authentifié, jamais d'URL publique. |
| `telephony:prune-recordings` | Purge configurable des fichiers audio, métadonnées conservées. |

### Modèle de données

- `call_records` : fournisseur, identifiant fournisseur stable, ligne de
  production associée (nullable), numéro brut et E.164, direction, statut,
  dates, durées, agent, état d'enregistrement, disque/chemin privé, type MIME,
  taille, date de récupération, dernière observation.
- `call_searches` : lot, utilisateur, période, fournisseurs, compteurs.
- `call_search_items` : un état par ligne × fournisseur
  (`queued`, `running`, `completed`, `no_results`, `failed`, `unsupported`,
  `ambiguous`, `skipped_no_phone`, `invalid_phone`).

**Déduplication :** unicité `(provider, provider_call_id)`. Jamais par numéro
seul : deux appels d'un même client restent distincts. Si Issabel n'expose pas
`uniqueid`, une empreinte SHA-256 de `calldate|src|dst|duration|billsec` est
utilisée et documentée ; elle ne remplace pas un identifiant stable quand il
existe.

## 4. Normalisation des numéros

- Région par défaut `FR` uniquement pour un numéro national sans indicatif.
- `06 12 34 56 78`, `+33 6 12 34 56 78`, `0033612345678` → `+33612345678`.
- Un `+212…` ou `+32…` **n'est jamais réécrit** en numéro français. La
  recherche le marque `invalid_phone` avec un message explicite.
- Les valeurs fournisseur nationales, internationales et « chiffres seuls »
  sont comparées après normalisation, jamais par égalité de chaînes.
- Un numéro partagé par plusieurs lignes de production rend la recherche
  **ambiguë** : aucune association automatique n'est effectuée.

## 5. Workflow de recherche

1. L'administrateur sélectionne une ou plusieurs lignes sur `/production`.
2. `POST /api/production/calls/search` valide les identifiants, la période
   (30 jours par défaut, 366 maximum) et la sélection (50 lignes maximum).
3. Le contrôleur crée un lot et dispatche `RunCallSearchJob` par ligne ×
   fournisseur. La requête HTTP ne contacte jamais un fournisseur.
4. L'interface interroge `GET /api/production/calls/searches/{id}` et affiche
   l'état par fournisseur.
5. Les appels sont écrits de façon idempotente ; les enregistrements Issabel
   peuvent être récupérés à la demande ou automatiquement si l'option est
   cochée.

### États et signification

| État | Signification |
|---|---|
| `completed` | Requête fournisseur réussie avec au moins un appel. |
| `no_results` | Requête réussie, zéro appel. **Seul cas autorisant « aucun appel ».** |
| `failed` | Authentification, permission, délai, quota, réponse malformée, configuration ou erreur fournisseur. |
| `unsupported` | Capacité non documentée (recherche Kavkom, téléchargement Kavkom). |
| `ambiguous` | Plusieurs lignes partagent le numéro : pas d'association. |
| `skipped_no_phone` | Aucun numéro saisi. |
| `invalid_phone` | Numéro absent/invalide ou étranger : jamais converti en FR. |

Un échec n'est jamais transformé en `no_results`. Une recherche sans résultat
ne supprime aucun appel existant.

## 6. Enregistrements privés

- Stockage hors racine web (`storage/app/private/recordings/...` par défaut).
- Nom de fichier opaque (`UUID`), jamais le nom fournisseur.
- Vérification de la taille, du type MIME et de la présence du fichier.
- Écriture dans un fichier temporaire, vérification, puis déplacement logique ;
  un échec supprime le fichier partiel.
- Un enregistrement déjà valide n'est **jamais** remplacé par un échec.
- Le chemin source Issabel est reconstruit sous `ISSABEL_RECORDING_ROOT` et
  vérifié par `realpath` ; aucune entrée navigateur ne choisit un chemin.
- Téléchargement via `GET /api/calls/{id}/recording`, réservé aux
  administrateurs actifs, avec `Cache-Control: private, no-store`.
- URL Kavkom jamais stockée : seul l'état « référence signalée » est conservé.
- Purge : `php artisan telephony:prune-recordings [--dry-run]`.

## 7. API

```
POST /api/production/calls/search              — lance un lot (202/201)
GET  /api/production/calls/searches/{id}       — statut + éléments
GET  /api/production/calls/searches/{id}/calls — appels du lot
GET  /api/production/{record}/calls            — appels d'une ligne
POST /api/calls/{call}/recording/retrieve      — récupération Issabel (202)
GET  /api/calls/{call}/recording               — téléchargement privé
GET  /api/telephony/diagnostics/{provider}     — test de connexion unitaire
                                                 (provider : kavkom | issabel)
POST /api/webhooks/kavkom/cdr                  — ingestion CDR (secret partagé)
```

Toutes les routes sauf le webhook sont dans le groupe `auth:sanctum` +
`admin`. Le webhook exige `KAVKOM_WEBHOOK_SECRET` dans l'en-tête configuré.

### Tester une connexion sans artisan

Chaque fournisseur se teste **séparément**, en lecture seule, sans lancer de
recherche d'appels ni de commande artisan :

```bash
cd backend
php artisan serve --port=8000            # une seule fois

# Jeton d'administrateur actif (voir POST /api/login)
TOKEN="…"

curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  http://127.0.0.1:8000/api/telephony/diagnostics/kavkom | jq

curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  http://127.0.0.1:8000/api/telephony/diagnostics/issabel | jq
```

Réponse (extrait) :

```json
{
  "data": {
    "provider": "issabel",
    "status": "warning",
    "checked_at": "2026-10-20T10:00:00.000000Z",
    "duration_ms": 18,
    "checks": [
      { "key": "enabled",           "status": "ok",      "message": "ISSABEL_ENABLED=true." },
      { "key": "connection",        "status": "ok",      "message": "Connexion établie en 12 ms." },
      { "key": "table",             "status": "ok",      "message": "Table « cdr » présente…" },
      { "key": "columns",           "status": "ok",      "message": "Colonnes requises présentes…" },
      { "key": "optional_columns",  "status": "ok",      "message": "Détectées : uniqueid, recordingfile." },
      { "key": "latest_cdr",        "status": "ok",      "message": "Dernier CDR enregistré : …" },
      { "key": "recording_root",    "status": "warning", "message": "…non configuré…" }
    ]
  }
}
```

| Fournisseur | Ce qui est réellement vérifié | Effet de bord |
|---|---|---|
| `kavkom` | `KAVKOM_WEBHOOK_ENABLED`, présence de `KAVKOM_WEBHOOK_SECRET` (valeur jamais affichée), en-tête attendu, endpoint d'ingestion, capacités documentées. | Aucun appel réseau, aucune écriture. |
| `issabel` | `ISSABEL_ENABLED`, complétude de `ISSABEL_DB_*`, **connexion MySQL réelle**, présence de la table CDR, colonnes requises (`calldate`, `src`, `dst`), colonnes optionnelles détectées, dernier `calldate`, lisibilité de `ISSABEL_RECORDING_ROOT`. | `SELECT` uniquement (`MAX(calldate)`), aucune écriture, aucun enregistrement copié. |

`status` est le pire état des contrôles : `ok`, `warning` (configuration
incomplète mais non bloquante, ex. répertoire audio absent) ou `error`
(connexion impossible, table/colonnes manquantes, secret absent). Chaque
contrôle en erreur expose aussi `code` avec le type déjà utilisé par la
recherche (`configuration`, `authentication`, `permission`, `timeout`,
`provider_error`). Un fournisseur désactivé n'est **pas** contacté : il renvoie
un `warning` explicite.

## 8. Tests exécutés

```bash
cd backend && php artisan test
cd frontend && npx tsc --noEmit && npm run build
```

Couverture Phase 2 (fournisseurs simulés, aucune credentials) :

- normalisation nationale/internationale et refus des indicatifs étrangers ;
- mapping CDR Issabel, empreinte de secours, configuration désactivée ;
- recherche réussie, vide, en échec, non prise en charge, ambiguë, sans numéro ;
- idempotence, appels distincts avec le même numéro, autorisation admin/agent ;
- webhook Kavkom : secret, idempotence, CDR sans identifiant, URL non stockée ;
- stockage privé : MIME, taille, nom opaque, traversal, fichier absent,
  préservation d'un bon enregistrement ;
- autorisation de téléchargement, chemin invalide, fichier manquant ;
- purge de rétention avec conservation des métadonnées.

## 9. Checklist de validation réelle

### Kavkom

1. Créer un endpoint HTTPS public pour `/api/webhooks/kavkom/cdr` et le
   déclarer dans **Réglages avancés → Intégrations → Webhook**.
2. Ajouter l'en-tête secret `KAVKOM_WEBHOOK_HEADER` avec la valeur
   `KAVKOM_WEBHOOK_SECRET` ; utiliser le bouton **Test** (requête vide → 202).
3. Activer la catégorie **CDR** et configurer le mapping des champs.
4. Passer un appel de test avec un numéro présent dans la table Production.
5. Vérifier l'appel stocké, l'association, l'état `reference_only` si
   `recording_url` est présent, et l'absence d'URL en base.
6. Vérifier que la recherche Kavkom affiche `unsupported` (pas « aucun appel »).
7. Confirmer avec Kavkom si un accès historique/téléchargement existe pour le
   compte ; sinon conserver la limitation documentée.

### Issabel

1. Créer un compte MySQL dédié avec `SELECT` uniquement sur `asteriskcdrdb`
   (jamais de compte administrateur, jamais d'exposition publique).
2. Vérifier le schéma : `SHOW COLUMNS FROM cdr;` et la présence de
   `recordingfile`, `uniqueid`, `calldate`.
3. Chercher un appel connu sur une période courte et comparer
   `src`/`dst`/`disposition` avec l'interface Issabel.
4. Confirmer le répertoire réel des enregistrements et le format de nom
   (`recordingfile`). Monter-le en lecture seule pour l'utilisateur Laravel.
5. Renseigner `ISSABEL_RECORDING_ROOT`, lancer une recherche, récupérer un
   fichier, vérifier le stockage privé et le téléchargement authentifié.
6. Tester un numéro absent (état `no_results`), un arrêt de la base (état
   `failed`), et un fichier audio absent (métadonnées conservées, état
   `missing`).
7. Valider la durée de conservation légale des enregistrements avec le
   responsable métier avant d'activer la purge.

## 10. Dépannage

| Symptôme | Cause probable / action |
|---|---|
| Kavkom `unsupported` | Comportement attendu : pas d'API de recherche documentée. Utiliser le webhook. |
| Webhook 401 | Secret absent ou différent de `KAVKOM_WEBHOOK_SECRET`, ou mauvais nom d'en-tête. |
| Webhook 503 | `KAVKOM_WEBHOOK_SECRET` vide. |
| Issabel `failed` / configuration | `ISSABEL_ENABLED`, hôte, base, compte ou table CDR non renseignés. |
| Issabel 401/403 | Compte MySQL refusé ou droits insuffisants ; vérifier le compte en lecture seule. |
| Diagnostic rapide | `GET /api/telephony/diagnostics/issabel` ou `/kavkom` (admin) : contrôles détaillés, fournisseur par fournisseur, en lecture seule. |
| Aucun appel malgré un numéro connu | Vérifier période, format fournisseur (`src`/`dst`), pagination/limite, et la présence du numéro dans ces colonnes. |
| Doublons | Vérifier la présence de `uniqueid` ; sinon l'empreinte documentée est utilisée. |
| Enregistrement introuvable | `recordingfile` absent, répertoire non monté, fichier expiré ou nom différent. |
| Téléchargement 404/410 | Aucun fichier stocké, chemin invalide, ou fichier disparu du stockage privé. |
| Recherche bloquée | Vérifier `php artisan queue:work` (connexion `database` par défaut) et les logs. |

## 11. Limites et décisions à prendre

- **Kavkom** : pas de recherche historique API documentée ; pas de
  téléchargement audio documenté. Décision métier : conserver l'ingestion
  webhook et traiter les enregistrements hors application, ou obtenir un accès
  documenté spécifique du compte.
- **Issabel** : méthode CDR MySQL + répertoire privé à confirmer sur
  l'installation réelle. Si l'accès direct n'est pas autorisé, prévoir un
  export CDR ou un petit service authentifié sur le PBX (hors périmètre actuel).
- **Revue manuelle des appels ambigus** : un numéro partagé produit des appels
  non associés (`match_basis = ambiguous`). Aucune interface d'affectation
  manuelle n'est fournie dans cette phase ; les appels restent visibles pour
  l'administrateur mais ne sont jamais attribués automatiquement à un client.
- **Données personnelles** : confirmer l'information des personnes enregistrées,
  les durées de conservation et les droits d'accès avant production.
- Aucune transcription, aucun Whisper, aucune détection de mots-clés et aucune
  décision de validation de contrat n'est implémentée ici. Ces éléments
  relèvent de la Phase 3.
