# Gestionnaire de Fiches Contact

Application web complète pour importer, consulter, nettoyer et exporter des
fichiers Excel « Fiche Contact » : imports audités, détection de doublons
téléphoniques, extraction conservatrice des contacts en doublon, exports
nettoyés et restaurations.

- `backend/` — API Laravel 12 (authentification Sanctum tokenizer, Excel via
  maatwebsite/excel + PhpSpreadsheet, tests PHPUnit).- `frontend/` — Next.js 16 (React 19, Tailwind 4) existant, connecté à l'API
  via un proxy la même-origine (`/api/*` → Laravel), sans qu'aucun écran ne
  dépende de données fictives pour les modules contact et production. Une page
  **Recherche globale** (`/recherche`) interroge les contacts de toutes les
  fiches, et chaque table (fiches, contacts d'une fiche, historique d'import,
  extractions, doublons, administrateurs, production) dispose de filtres, d'un
  tri et d'une pagination côté serveur.

Le module **Production** (Phase 1, §12) synchronise des lignes contrat/client
depuis un **Google Sheets** et gère les numéros de téléphone des clients.

## 1. Prérequis

- PHP >= 8.2 avec extensions usuelles (`pdo_sqlite`, `mbstring`, `zip`, `gd`, `intl`).
- Composer >= 2.4
- Node.js >= 20, npm
- (Optionnel production) MySQL >= 8 et un serveur web capable d'héberger les deux apps.

## 2. Installation

### Backend

```bash
cd backend
composer install
cp .env.example .env            # si première installation
php artisan key:generate
php artisan migrate             # crée le schéma (SQLite par défaut : database/database.sqlite)
php artisan admin:create        # premier administrateur (invites interactives)
```

La commande d'administration accepte aussi des options (utilisées en CI) :

```bash
php artisan admin:create --name="Admin Fiches" --email="admin@fiches.ma" --password="admin1234"
```

Compte de démonstration créé ainsi (dev) : `admin@fiches.ma` / `admin1234`.

### Frontend

```bash
cd frontend
npm install
cp .env.example .env.local      # optionnel : BACKEND_URL (défaut http://localhost:8000)
npm run dev                     # http://localhost:3000
```

## 3. Exécution en développement

1. `php artisan serve --port=8000` (dossier `backend/`) — l'API.
2. `npm run dev` (dossier `frontend/`) — le site.
3. Ouvrir http://localhost:3000 ; le proxy `next.config.ts` transmet `/api/*`
   au backend : aucune configuration CORS ni cookie cross-site.

Tests backend :

```bash
cd backend && php artisan test
```

## 4. Variables d'environnement

Backend (`backend/.env`) :

| Variable | Rôle | Défaut dev |
|---|---|---|
| `DB_CONNECTION` | SGBD (`sqlite` conseillé en dev, support `mysql`) | `sqlite` |
| `DB_DATABASE` | Chemin du fichier SQLite | `database/database.sqlite` |
| `MYSQL_*` | Si MySQL : `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | — |
| `APP_URL` | URL publique du backend | `http://localhost:8000` |
| `APP_DEBUG` | **Doit rester `false` en production** | `true` en dev |
| `FILESYSTEM_DISK` | Disque des fichiers importés (`local`, privé) | `local` |
| `GOOGLE_SHEETS_SPREADSHEET_ID` | Classeur Google Sheets source de la Production (voir §12) | — |
| `GOOGLE_SHEETS_RANGE` | Plage lue en lecture seule | `A1:Z5000` |
| `GOOGLE_APPLICATION_CREDENTIALS` | JSON du compte de service (**jamais versionné**) | — |

Frontend (`frontend/.env.local`) :

| Variable | Rôle | Défaut |
|---|---|---|
| `BACKEND_URL` | Cible du proxy `/api/*` (Suffixe serveur) | `http://localhost:8000` |

Aucun secret n'est versionné ; les mots de passe sont hachés (bcrypt) et
jamais retournés par l'API.

## 5. Structure Excel « Fiche Contact »

En-têtes attendus (tout en haut de la première feuille), dans l'ordre :

`NOM` · `PRENOM` · `ADRESSE` · `CP` · `VILLE` · `TEL` · `Email` · `Date de naissance`

- `NOM` et `PRENOM` sont **obligatoires** (manquants → tout l'import est refusé
  avec la liste des en-têtes absents ; l'historique trace la tentative).
- Variants tolérés : casse, espaces, accents (`Prénom`, `DATE DE NAISSANCE`,
  `Telephone`…). Les colonnes non reconnues ne sont jamais interprétées.
- Codes postaux et téléphones : chaînes — zéros initiaux conservés (`06801`,
  `06 12 34 56 78`), jamais convertis en nombres.
- Dates acceptées : séries Excel, `JJ/MM/AAAA`, `JJ-MM-AAAA`, `AAAA-MM-JJ`,
  `JJ.MM.AAAA` (jamais de valeur trompeuse : date illisible ⇒ ligne rejetée).
- Lignes : rejetées avec numéro de ligne, champ et motif (visible dans
  l'historique d'import) ; lignes entièrement vides = compteurs « ignored ».
- Imports identiques (même contenu exact, hash SHA-256) : refusés tant que
  l'administrateur ne confirme pas explicitement ; autant de fiches distinctes
  créées qu'imports confirmés, sans aucune fusion automatique.

## 6. Détection de doublons & normalisation téléphonique

- Portée : **au sein d'une fiche** (jamais entre fiches).
- Deux contacts sont groupés uniquement si leurs numéros normalisés sont
  identiques et numériques (>= 6 chiffres).
- Normalisation conservatrice : suppression des séparateurs bénins (espaces,
  points, tirets, parenthèses, barres obliques, `+` initial), reliquat
  purement numérique. Exemple : `0612345678`, `06 12 34 56 78`,
  `06-12-34-56-78` sont équivalents ; la valeur `tel` d'origine est conservée.
- Limites documentées : aucune hypothèse de pays — `0612345678` (local) et
  `+212612345678` (international) ne sont **pas** regroupés ; un numéro
  contenant des lettres ou trop court est exclu et individuellement compté.
- Un scan ne modifie **jamais** les contacts et n'est jamais une extraction ;
  les contacts extraits disparaissent du scan (contacts actifs uniquement).

## 7. Extraction, export nettoyé, restauration

1. Le scan de doublons affiche des groupes dépliables ; l'administrateur
   coche **individuellement** les contacts à extraire (extraire 2 garder 1 est
   le cas nominal).
2. La confirmation (dialogue : fiche, sélection, restants prévus) appelle
   `POST /api/files/{id}/duplicates/extract`. Les contacts sont associés à une
   **opération d'extraction** (`duplicate_extractions` + items, contrainte SQL
   d'unicité : un contact ne peut être actif que dans une seule extraction),
   dans une transaction avec verrou (`lockForUpdate`) — les refus (contact
   d'une autre fiche, id fabricé, déjà extrait) annulent tout.
3. Les contacts extraits sont **conservés** ; seule leur visibilité dans le
   « jeu actif » change (scope centralisé `Contact::active()` utilisé partout :
   détail, compteurs, doublons, exports).
4. Export nettoyé = contacts actifs uniquement, en-têtes d'origine, vrai
   XLSX, dates `JJ/MM/AAAA`, garde anti-formules (`=`, `+`, `@` préfixés d'une
   apostrophe sans altérer la base).
5. Export « extraits » = contacts de l'opération (même gabarit d'en-têtes).
6. **Restauration** : l'opération repasse « restored », le record de
   restauration archive les identifiants, les contacts redeviennent actifs,
   aucun contact n'est recréé et l'historique de l'opération d'origine reste
   intact. Après restauration, les contacts réapparaissent dans les scans et
   exports nettoyés.

Modèle de statuts d'import : fiche `imported` / `partial` (lignes success +
rejetées) / `failed` ; historique `completed` / `partial` / `failed`.

## 8. Rétention & suppression

- L'original uploadé est conservé sur le disque **privé** `local`
  (`storage/app/fiches/…`) et téléchargé uniquement via un endpoint
  authentifié — jamais d'URL publique.
- DELETE `/api/files/{id}` = **archivage** (soft delete) : la fiche et ses
  contacts disparaissent des listes globales, l'historique d'import et
  d'extraction reste consultable et exportable. Les contacts de fiches
  archivées sont exclus des exports globaux.
- Aucune route ne supprime des contacts physiquement.

## 9. API (extrait)

```
POST   /api/login                              — token Sanctum (rate-limité)
POST   /api/logout                             — révocation du token courant
GET    /api/user | POST /api/user/password
GET    /api/dashboard
GET    /api/files | POST /api/files/import
GET    /api/files/{id} | /files/{id}/contacts
GET    /api/files/{id}/export | /files/{id}/cleaned-export | /files/{id}/download
DELETE /api/files/{id}
GET    /api/files/{id}/duplicates              — scan lecture seule
POST   /api/files/{id}/duplicates/extract      — extraction transactionnelle
GET    /api/import-history[/{id}]
GET    /api/contacts | GET /api/contacts/export
GET    /api/contact-search                    — recherche globale (toutes fiches)
         params : q, file_id, status(active|extracted|all), ville, cp,
         imported_from, imported_to, extracted_from, extracted_to,
         sort_by, sort_direction, page, per_page
GET    /api/contact-search/export             — export filtré (tous les résultats)
GET    /api/duplicate-extractions[/{id}] | …/export | POST …/restore
GET|POST|PATCH|DELETE /api/admins[/{id}]       — administrateurs (dernier actif protégé)
GET    /api/production                        — table Production (recherche/filtres/tri/pagination)
POST   /api/production/sync                   — synchronisation Google Sheets (Phase 1)
GET    /api/production/syncs                  — historique des synchronisations
PATCH  /api/production/{id}/phone             — numéro client (saisie locale)
```

Toutes les routes sauf `login` exigent un token Sanctum et un compte
**administrateur actif** (`EnsureAdmin`). Aucune inscription publique.

## 10. Tests automatisés (backend)

`php artisan test` couvre :

- authentification (identifiants, suspension, rate limiting, révocation),
- gestion des administrateurs (hash, dernier actif, auto-suppression),
- imports (fichier valide, variants d'en-têtes, en-têtes manquants, lignes
  invalides, doublon refusé puis confirmé, fichier corrompu, zéros initiaux),
- doublons (groupement, normalisation, exclusions, portée par fiche,
  extraits programmés/restaurés),
- extraction (sélection individuelle, refus trans-fiche/ids invalides,
  double extraction, résultat nettoyé cohérent), exports complète/nettoyé/
  extraits (lecture vraie du XLSX produit),
- restauration (contacts réactifs sans duplication, audit conservé),
- recherche globale (nom/prénom/téléphone/email/ville/CP/adresse/fiche source,
  formatage téléphonique, recherches inter-fiches, filtres combinés, statut
  actif/extrait, tri, pagination, plage de dates, export filtré complet),
- filtres/tris/pagination de chaque table (fiches, contacts, historique,
  extractions, doublons, administrateurs) avec rejet des tris inconnus.

## 11. Déploiement (résumé)

1. Backend : `composer install --no-dev --optimize-autoloader`, config `.env`
   (MySQL, `APP_DEBUG=false`, `APP_KEY`), `php artisan migrate --force`,
   `php artisan config:cache` ; servir `public/` (PHP-FPM/Nginx) et fournir
   un stockage privé persistant pour `storage/app/fiches`.
2. Frontend : `npm ci && npm run build && npm run start` (ou PM2) avec
   `BACKEND_URL` pointant vers l'API ; le proxy `/api` reste même-origine.
3. Créer le premier administrateur en CLI (`admin:create`) et déduire les
   comptes opérateurs dans l'application. Jamais de mot de passe en clair
   dans les dépôts.

## 12. Module Production — Phase 1 (synchronisation Google Sheets)

Le module **Production** importe des lignes contrat/client depuis un classeur
Google Sheets et permet aux administrateurs de saisir les numéros de téléphone
des clients, puis de sélectionner des lignes en vue d'une future vérification
d'appels.

> **Périmètre** : les Phases 1 et 2 sont implémentées. La Phase 2 ajoute la
> recherche d'appels (Issabel par CDR, Kavkom par webhook CDR), le stockage de
> métadonnées et la récupération d'enregistrements privés. La transcription,
> Whisper et la validation de contrat restent **hors périmètre** (Phase 3).
> Voir `docs/phase-2-telephony.md` pour les capacités vérifiées, les limites
> fournisseur et la checklist de validation réelle.

### 12.1 Configuration

1. Dans Google Cloud : créer un **compte de service**, activer l'**API Google
   Sheets**, puis télécharger sa clé JSON.
2. **Partager le classeur** avec l'adresse `client_email` du compte de service
   (droit de lecture suffit : la portée demandée est
   `spreadsheets.readonly`). Sans ce partage, la synchronisation renvoie une
   erreur 403 explicite.
3. Déposer le JSON **hors versionnement** (il est ignoré par `.gitignore`) :
   `backend/storage/app/google/service-account.json`.
4. Renseigner `backend/.env` :

| Variable | Rôle | Exemple |
|---|---|---|
| `GOOGLE_SHEETS_SPREADSHEET_ID` | Identifiant du classeur (dans son URL) | `1AbC…xyz` |
| `GOOGLE_SHEETS_RANGE` | Plage A1 lue en lecture seule | `Production!A1:Z5000` |
| `GOOGLE_APPLICATION_CREDENTIALS` | Chemin du JSON de compte de service (relatif à `backend/` ou absolu) | `storage/app/google/service-account.json` |
| `GOOGLE_SHEETS_HEADER_ROW` | Ligne (1-indexée) de recherche des en-têtes | `1` |
| `GOOGLE_SHEETS_TIMEOUT` | Délai HTTP (secondes) | `30` |

Puis `php artisan config:clear` (ou `config:cache` en production).

### 12.2 Correspondance des colonnes et identité

La correspondance **en-tête de feuille → champ interne** est explicite dans
`backend/config/production.php` (`mapping`). Adaptez les libellés à votre feuille :
ils sont comparés après normalisation (casse, accents et espaces ignorés).

| Champ interne | Possession | Rôle |
|---|---|---|
| `contract_reference` | Feuille | Numéro de contrat — **composante de la clé métier** |
| `client_name` | Feuille | Nom du client — **composante de la clé métier** |
| `city`, `product`, `status` | Feuille | Colonnes affichées/filtrables |
| `phone` | **Application** | Numéro saisi par l'utilisateur, jamais écrasé |

- **Identité** (`identity`) : `contract_reference` + `client_name`. La clé est la
  concaténation normalisée de ces valeurs, hachée en SHA-256 et stockée dans
  `production_records.source_key` (contrainte d'unicité). Le **numéro de ligne
  n'est jamais une identité** : il n'est conservé que pour l'information.
- **Champs obligatoires** (`required`) : une ligne dont un champ obligatoire est
  vide est **rejetée et signalée** (ligne, champ, motif) sans interrompre la
  synchronisation.
- Un en-tête obligatoire absent de la feuille fait **échouer la synchronisation**
  avec un message actionnable : aucune colonne n'est devinée, aucune ligne n'est
  écrite.

### 12.3 Comportement de la synchronisation

- Déclenchement : bouton **« Synchroniser Google Sheets »** (page `/production`)
  ou `POST /api/production/sync` (administrateurs authentifiés uniquement). Le
  classeur et la plage ne sont **jamais** acceptés depuis le navigateur.
- **Idempotence** : rejouer une feuille identique met à jour les lignes
  existantes (via `source_key`), sans créer de doublon.
- **Champs mis à jour** : uniquement ceux possédés par la feuille. Le téléphone
  saisi dans l'application est **préservé**, à chaque synchronisation.
- **Non destructif** : une ligne absente d'une réponse n'est **jamais**
  supprimée. Aucune politique de suppression de source n'existe en Phase 1.
- **Résumé** : chaque synchronisation enregistre un `ProductionSync`
  (`completed` / `partial` / `failed`) avec lignes lues, créées, mises à jour,
  vides ignorées, rejetées et le détail des rejets. Les erreurs ne contiennent
  ni données personnelles ni secrets.
- Écritures groupées par lots de 500 dans une transaction : un échec laisse la
  base dans son état précédent.

### 12.4 Numéros de téléphone

- Validation côté serveur avec la bibliothèque **libphonenumber**
  (`giggsey/libphonenumber-for-php`, France `FR` comme région par défaut) via
  `App\Services\PhoneValidator`. La règle clef est
  `isValidNumberForRegion($number, "FR")` : un numéro valide pour la France est
  accepté, un numéro qui porte explicitement un indicatif étranger est refusé —
  un `+32 412 34 56 78` n'est **jamais réinterprété** comme un numéro français.
- Formats acceptés (national et international français) : `06 12 34 56 78`,
  `07 12 34 56 78`, `01 42 34 56 78`, `+33 6 12 34 56 78`, `+33 7 12 34 56 78`,
  `+33 1 42 34 56 78`, `0033612345678` — les séparateurs courants (espace, point,
  tiret, slash, parenthèses) sont tolérés.
- Formes rejetées avec message explicite : un numéro introuvable
  (`abc`), un numéro trop court/long pour le plan de numérotation français
  (`06 12 34 56 7`), et un numéro étranger (`+32 412 34 56 78`).
- Quand un numéro valide est saisi en format national, il est **normalisé en
  E.164** pour les recherches auprès des fournisseurs téléphoniques :
  `06 12 34 56 78 → +33612345678`. La valeur saisie (original) est conservée
  pour l'affichage et l'audit.
- La colonne `phone` est saisie dans l'application ; une colonne dérivée
  `phone_normalise` (chiffres) sert à la recherche dans la table. La Phase 2
  utilise `App\Services\Telephony\TelephonyNumber` pour la forme E.164 et la
  comparaison avec les valeurs Kavkom/Issabel ; `App\Support\TelephonyNormalizer`
  reste un adaptateur de compatibilité.
- **Synchronisation** : la synchronisation Google Sheets ne modifie jamais le
  champ `phone` (champs possédés par la feuille uniquement) ; un numéro saisi
  manuellement survit à chaque import.
- Limite assumée (recherche dans la table) : la recherche sur la table
  (`search=…`) retrouve un numéro par sous-chaîne de la valeur affichée ou de
  sa forme chiffres, mais les formes locale (`0612345678`) et internationale
  (`+33612345678`) sont toutes deux trouvées si elles ont été saisies — la
  recherche ne déduit pas l'E.164 depuis un national tapé à la volée. Pour une
  recherche par E.164 au niveau des fournisseurs, utiliser la colonne E.164
  une fois disponible ou normaliser d'abord avec `PhoneValidator`.

### 12.5 API

```
GET   /api/production                — liste (search, city, product, status, has_phone,
                                        sort_by, sort_direction, page, per_page)
POST  /api/production/sync           — synchronisation Google Sheets (idempotente)
GET   /api/production/syncs          — historique court des synchronisations
PATCH /api/production/{id}/phone     — saisie du numéro client
```

### 12.6 Interface

La page `/production` (entrée « Production » de la barre latérale) propose la
recherche (contrat, client, téléphone), des filtres (ville, produit, statut,
présence d'un numéro) alimentés par les valeurs réellement présentes, un tri,
la pagination, l'édition en ligne du numéro, la sélection de lignes (toutes les
lignes affichées de la page courante) et le résumé de la dernière synchronisation.

**Sélection** : elle porte sur la page affichée et est **effacée** à chaque
changement de page, de filtre, de tri ou de recherche (un bandeau l'indique) ;
aucune sélection cachée n'est soumise.

### 12.7 Vérification manuelle

```bash
cd backend
php artisan migrate            # crée production_records / production_syncs
php artisan test               # suite complète (les tests Google utilisent Http::fake)
php artisan serve --port=8000

cd ../frontend && npm run dev  # http://localhost:3000/production
```

1. Renseigner les variables Google + déposer le JSON, partager le classeur.
2. Se connecter en administrateur, ouvrir **Production**, lancer « Synchroniser
   Google Sheets », vérifier le résumé (lues / créées / mises à jour / ignorées /
   rejetées) et comparer les lignes affichées à la feuille source.
3. Saisir un numéro sur une ligne, relancer la synchronisation, vérifier que le
   numéro est **inchangé** et que les autres champs suivent la feuille.
4. Rechercher, filtrer, changer de page, sélectionner des lignes puis changer de
   page : le bandeau de sélection doit disparaître avec la sélection.
5. Cas d'échec : retirer `GOOGLE_SHEETS_SPREADSHEET_ID` (message « configuration
   incomplète ») ; retirer le partage du classeur (message 403 « partagez la
   feuille ») ; renommer un en-tête obligatoire (message « en-tête introuvable »).
   Consulter `storage/logs/laravel.log` et le bandeau « Dernière synchronisation ».

### 12.8 Dépannage

| Symptôme | Cause probable / correction |
|---|---|
| 401 / « identifiants Google invalides » | API Google Sheets non activée, JSON de compte de service incorrect ou clé expirée |
| 403 « partagez la feuille » | Classeur non partagé avec `client_email` du compte de service |
| 404 / « classeur introuvable » | `GOOGLE_SHEETS_SPREADSHEET_ID` ou `GOOGLE_SHEETS_RANGE` erronés (nom d'onglet inclus) |
| « en-tête(s) obligatoire(s) introuvable(s) » | Libellés de colonnes différents : compléter `mapping` dans `config/production.php` |
| Doublons après synchronisation | Champs d'identité insuffisants ou instables : ajuster `identity` |
| Téléphones qui disparaissent | Ne se produit pas par conception ; vérifier qu'aucun code n'écrit `phone` hors `PATCH /phone` |
| Synchronisation lente | La lecture est bornée par `GOOGLE_SHEETS_RANGE` ; réduire la plage. Un import en file d'attente pour de très gros volumes est un évolution future documentée |

## 13. Module Téléphonie — Phase 2

La Phase 2 est documentée en détail dans **`docs/phase-2-telephony.md`**.
Points essentiels :

- **Kavkom** : l'API documentée ne fournit pas de recherche d'historique ni de
  téléchargement audio. Les CDR sont ingérés par webhook (secret partagé) ; la
  recherche affiche un état `unsupported`, jamais « aucun appel ».
- **Issabel** : lecture d'une base CDR MySQL dédiée en **lecture seule**, avec
  colonnes optionnelles détectées, et récupération des fichiers audio depuis un
  répertoire privé configuré. Cette méthode doit être confirmée sur le PBX réel.
- **Numéros** : normalisation E.164 avec `FR` par défaut ; un indicatif étranger
  n'est jamais réécrit. Un numéro partagé rend la recherche ambiguë et bloque
  toute association automatique.
- **Asynchrone** : `POST /api/production/calls/search` crée un lot et dispatche
  des jobs ; l'interface interroge le statut par fournisseur. Un échec, une
  capacité non prise en charge et un vrai « aucun appel » sont distincts.
- **Enregistrements** : stockage privé, nom opaque, type MIME et taille
  vérifiés, téléchargement réservé aux administrateurs, purge configurable via
  `php artisan telephony:prune-recordings`.

**Aucun test live Kavkom/Issabel n'a été exécuté** dans cet environnement
(absence d'identifiants et d'accès). Les tests automatisés utilisent des
fournisseurs simulés. La checklist de validation réelle et les blocages sont
dans `docs/phase-2-telephony.md`.

## 14. Limites connues

- La détection de doublons téléphoniques (§6/§7) ne rapproche pas les formats
  locaux et internationaux (choix assumé : éviter les faux positifs).
- La validation téléphonique de la saisie Production (§12.4) utilise
  `libphonenumber` avec la région `FR` par défaut et exige un numéro valide
  pour la France : un numéro étranger (ex. `+32 …`) est refusé et ne sera pas
  réinterprété comme français. La Phase 2 réutilise cette normalisation pour
  les recherches Kavkom/Issabel via `TelephonyNumber` ; les appels réels aux
  fournisseurs n'ont pas été validés dans cet environnement (voir §13 et
  `docs/phase-2-telephony.md`).
- Imports synchrones : conçus pour des fichiers de taille raisonnable
  (≤ 10 Mo) ; de très gros volumes justifieraient un import en file d'attente.
- Pages « héréditaires » de la démo initiale (agents/commissions/résiliations,
  tableau de bord, mon espace) restent alimentées par le jeu de démonstration
  mock : le périmètre des demandes couvre les modules contacts puis production ;
  l'authentification, elle, est réelle et commune.
- Module Production (Phase 1) : la synchronisation est synchrone et bornée par
  la plage configurée ; elle n'a pas été validée contre un classeur Google réel
  dans cet environnement (les tests utilisent un client simulé / `Http::fake`).
  L'édition du téléphone, la validation libphonenumber et la protection contre
  l'écrasement par la synchronisation sont implémentées.
- Module Téléphonie (Phase 2) : les adaptateurs sont testés avec des réponses
  simulées, sans identifiants réels. Kavkom ne documente pas de recherche
  d'historique ni de téléchargement audio ; Issabel doit être confirmé (schéma
  CDR, colonne `recordingfile`, répertoire des enregistrements, droits du compte
  MySQL). Aucune transcription ni décision de validation n'est implémentée.
- Suppression d'un compte administrateur : immédiate (révocation de ses
  tokens) ; l'audit d'import/extraction conserve les noms affichés au moment
  des opérations, sauf re-création ultérieure du même nom.
- Erreurs de synchronisation : chaque tentative crée un `ProductionSync` (succès
  comme échec) avec résumé et erreurs par ligne, et les exceptions connues
  (`GoogleSheetsException`, `ProductionMappingException`) remontent un message
  actionnable en HTTP 422. Les erreurs inattendues sont journalisées via
  `report($e)` (fichier `storage/logs/laravel.log`) avec l'identifiant de la
  synchronisation, et l'utilisateur reçoit un message générique. Un vrai
  classeur Google est nécessaire pour dépanner les cas réseau/API.
