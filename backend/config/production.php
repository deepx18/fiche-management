<?php

/**
 * Phase 1 — Synchronisation « Production » depuis Google Sheets.
 *
 * Rien n'est inventé côté données : la correspondance entre les en-têtes de la
 * feuille et les champs internes de l'application est **explicite** ici. Si la
 * feuille ne contient pas un en-tête correspondant à un champ obligatoire, la
 * synchronisation échoue avec un message actionnable (aucune donnée n'est
 * fabriquée et aucune ligne n'est écrite).
 *
 * Les libellés sont comparés après normalisation : casse, accents et espaces
 * superflus sont ignorés (voir ProductionSynchronizer::normalizeHeader()).
 */
return [

    // Identifiant du classeur Google Sheets (présent dans l'URL du document).
    'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),

    // Plage A1 lue en lecture seule, ex. « Production!A1:Z5000 ».
    'range' => env('GOOGLE_SHEETS_RANGE', 'A1:Z5000'),

    // Chemin du JSON de compte de service (jamais versionné).
    'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),

    // Ligne (1-indexée) à partir de laquelle chercher la ligne d'en-têtes.
    'header_row' => (int) env('GOOGLE_SHEETS_HEADER_ROW', 1),

    // Délai maximum (secondes) des appels HTTP vers Google.
    'timeout' => (int) env('GOOGLE_SHEETS_TIMEOUT', 30),

    // Portée minimale : lecture seule du classeur.
    'scopes' => ['https://www.googleapis.com/auth/spreadsheets.readonly'],

    /*
    |----------------------------------------------------------------------
    | Correspondance en-têtes de la feuille → champs internes
    |----------------------------------------------------------------------
    | Clé = champ interne (fixé par le schéma de l'application), valeur =
    | liste des libellés d'en-tête acceptés. Adaptez cette liste aux
    | en-têtes réellement présents dans votre feuille ; le premier libellé
    | reconnu gagne, un champ n'est mappé qu'une seule fois.
    |
    | Champs « feuille » disponibles :
    |   contract_reference, client_name, city, product, status
    */
    'mapping' => [
        'contract_reference' => [
            'n° contrat', 'no contrat', 'numero contrat', 'numéro contrat',
            'contrat', 'reference', 'référence', 'contract_reference',
        ],
        'client_name' => [
            'client', 'nom client', 'nom du client', 'assure', 'assuré',
            'client_name',
        ],
        'city' => ['ville', 'city'],
        'product' => ['produit', 'product', 'formule'],
        'status' => ['statut', 'status', 'etat', 'état'],
    ],

    /*
    |----------------------------------------------------------------------
    | Identité d'une ligne (idempotence)
    |----------------------------------------------------------------------
    | La clé métier est la concaténation normalisée de ces champs, hachée en
    | SHA-256 et stockée dans production_records.source_key (unique). Deux
    | lignes de la feuille partageant la même clé sont considérées comme la
    | même entité : la seconde écrase la première (mise à jour, jamais de
    | doublon). Les numéros de ligne ne sont jamais utilisés comme identité.
    */
    'identity' => ['contract_reference', 'client_name'],

    /*
    |----------------------------------------------------------------------
    | Champs obligatoires
    |----------------------------------------------------------------------
    | Une ligne dont un de ces champs est vide est rejetée (comptée en
    | « failed » avec le champ et le motif), sans interrompre la synchro.
    */
    'required' => ['contract_reference', 'client_name'],

    /*
    |----------------------------------------------------------------------
    | Champs possédés par l'application
    |----------------------------------------------------------------------
    | `phone` est saisi par les utilisateurs et n'est JAMAIS écrit par la
    | synchronisation (voir ProductionSynchronizer) : un numéro saisi
    | manuellement survit à chaque import.
    */
    'application_owned' => ['phone'],

];
