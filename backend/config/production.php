<?php

/**
 * Synchronisation « Production » depuis Google Sheets.
 *
 * La correspondance entre les en-têtes réels de la feuille et les champs
 * internes est **explicite** ici. Aucune correspondance approximative n'est
 * faite : les 11 en-têtes doivent être présents, une seule fois chacun, sinon
 * la synchronisation est refusée avant toute écriture.
 *
 * Normalisation des en-têtes (voir App\Services\Production\ProductionSheetSchema) :
 * casse, accents, espaces multiples, espaces insécables et apostrophes
 * typographiques sont neutralisés. Aucun alias supplémentaire n'est déclaré,
 * car les libellés réels ont été vérifiés dans la feuille de production.
 */
return [

    // Identifiant du classeur Google Sheets (présent dans l'URL du document).
    'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),

    // Plage A1 lue en lecture seule, ex. « Production!A1:Z5000 ». La borne de
    // lignes sert aussi à détecter une lecture tronquée (voir le synchroniseur).
    'range' => env('GOOGLE_SHEETS_RANGE', 'A1:Z5000'),

    // Chemin du JSON de compte de service (jamais versionné).
    'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),

    // Ligne (1-indexée) à partir de laquelle chercher la ligne d'en-têtes.
    'header_row' => (int) env('GOOGLE_SHEETS_HEADER_ROW', 1),

    // Délai maximum (secondes) des appels HTTP vers Google.
    'timeout' => (int) env('GOOGLE_SHEETS_TIMEOUT', 30),

    // Reprises bornées sur erreurs transitoires (429, 5xx, réseau).
    'retry' => [
        'attempts' => (int) env('GOOGLE_SHEETS_RETRY_ATTEMPTS', 3),
        'base_delay_ms' => (int) env('GOOGLE_SHEETS_RETRY_DELAY_MS', 400),
    ],

    // Portée minimale : lecture seule du classeur.
    'scopes' => ['https://www.googleapis.com/auth/spreadsheets.readonly'],

    /*
    |----------------------------------------------------------------------
    | Correspondance en-têtes de la feuille → champs canoniques
    |----------------------------------------------------------------------
    | Clé = champ canonique utilisé par le synchroniseur, l'API et l'interface.
    | Valeur = libellés acceptés (normalisés). La correspondance physique est
    | définie dans App\Models\ProductionRecord::SHEET_FIELDS.
    |
    | Aucun alias non vérifié n'est ajouté : un libellé inconnu est signalé,
    | jamais deviné.
    */
    'mapping' => [
        'date' => ['DATE'],
        'cial' => ['CIAL'],
        'nom' => ['NOM'],
        'prenom' => ['PRENOM'],
        'cotisation' => ['COTISATION'],
        'prime_annuelle' => ['PRIME ANNUELLE'],
        'cie' => ['CIE'],
        'produit' => ['PRODUIT'],
        'date_effet' => ["Date d'effet"],
        'validation' => ['VALIDATION'],
        'type_signature' => ['TYPE DE SIGNATURE'],
    ],

    /*
    |----------------------------------------------------------------------
    | Identité d'une ligne (idempotence)
    |----------------------------------------------------------------------
    | Aucun identifiant métier unique n'existe dans la feuille (CIAL, nom ou
    | date ne sont pas garantis uniques). La clé est donc la concaténation
    | normalisée de 7 champs stables : date, cial, nom, prénom, CIE, produit et
    | date d'effet. Deux lignes identiques sur ces 7 champs sont la même
    | entité ; toute différence (y compris un nom corrigé) crée une ligne
    | distincte plutôt que d'écraser une ligne existante. Les montants, la
    | validation et le type de signature ne font pas partie de l'identité : ils
    | peuvent évoluer sans casser l'idempotence.
    |
    | Le numéro de ligne de la feuille n'est jamais une identité.
    */
    'identity' => ['date', 'cial', 'nom', 'prenom', 'cie', 'produit', 'date_effet'],

    /*
    |----------------------------------------------------------------------
    | Champs obligatoires par ligne
    |----------------------------------------------------------------------
    | Une ligne dont un de ces champs est vide (ou dont une date est invalide)
    | est rejetée avec un diagnostic, sans interrompre les autres lignes.
    | COTISATION, PRIME ANNUELLE, VALIDATION et TYPE DE SIGNATURE peuvent être
    | vides dans la source ; s'ils sont renseignés, ils doivent être valides.
    */
    'required' => ['date', 'cial', 'nom', 'prenom', 'cie', 'produit', 'date_effet'],

    /*
    |----------------------------------------------------------------------
    | Possession des champs
    |----------------------------------------------------------------------
    | `phone` et `phone_normalise` sont saisis dans l'application : la
    | synchronisation ne les écrit JAMAIS. `reference_contrat` est générée par
    | l'application et n'est jamais écrasée par la feuille.
    */
    'application_owned' => ['phone', 'phone_normalise'],
    'generated' => ['reference_contrat'],

];
