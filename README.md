# Gestionnaire de Fiches Contact

Application web complète pour importer, consulter, nettoyer et exporter des
fichiers Excel « Fiche Contact » : imports audités, détection de doublons
téléphoniques, extraction conservatrice des contacts en doublon, exports
nettoyés et restaurations.

- `backend/` — API Laravel 12 (authentification Sanctum tokenizer, Excel via
  maatwebsite/excel + PhpSpreadsheet, tests PHPUnit).- `frontend/` — Next.js 16 (React 19, Tailwind 4) existant, connecté à l'API
  via un proxy la même-origine (`/api/*` → Laravel), sans qu'aucun écran ne
dépende de données fictives pour le module contact. Une page **Recherche
globale** (`/recherche`) interroge les contacts de toutes les fiches, et
chaque table (fiches, contacts d'une fiche, historique d'import, extractions,
doublons, administrateurs) dispose de filtres, d'un tri et d'une pagination
côté serveur.

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

## 12. Limites connues

- La détection téléphonique ne rapproche pas formats locaux et internationaux
  (choix assumé : éviter les faux positifs, cf. §7).
- Imports synchrones : conçus pour des fichiers de taille raisonnable
  (≤ 10 Mo) ; de très gros volumes justifieraient un import en file d'attente.
- Pages « héréditaires » de la démo initiale (contrats/agents/commissions/
  résiliations du module AssurCore) restent alimentées par le jeu de
  démonstration mock : le scope de la demande couvre le module contacts ;
  l'authentification, elle, est réelle et commune.
- Suppression d'un compte administrateur : immédiate (révocation de ses
  tokens) ; l'audit d'import/extraction conserve les noms affichés au moment
  des opérations, sauf re-création ultérieure du même nom.
