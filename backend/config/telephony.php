<?php

/**
 * Phase 2 — Intégration téléphonie (Kavkom / Issabel).
 *
 * Aucun identifiant réel n'est versionné. Les valeurs sensibles sont lues dans
 * l'environnement et ne doivent jamais être renvoyées au navigateur ni
 * journalisées.
 *
 * Capacités vérifiées dans la documentation officielle (octobre 2026) :
 *  - Kavkom expose une API PBX pour les extensions et les appels en cours,
 *    ainsi qu'un webhook poussant les CDR terminés (dont `recording_url`).
 *    Aucune API documentée de recherche d'historique d'appels n'existe :
 *    la recherche Kavkom est donc déclarée NON PRISE EN CHARGE.
 *  - Issabel n'expose pas de CDR dans son API REST documentée. L'intégration
 *    repose sur une connexion MySQL en lecture seule vers la base CDR
 *    (`asteriskcdrdb.cdr`) et, si configuré, un répertoire d'enregistrements
 *    privé accessible en lecture. Ces deux points restent à vérifier sur
 *    l'installation réelle : voir docs/phase-2-telephony.md.
 */
return [

    // Région par défaut uniquement pour les numéros nationaux sans indicatif.
    'default_region' => 'FR',

    'search' => [
        // Politique de dates par défaut : jamais tout l'historique.
        'default_days' => (int) env('TELEPHONY_SEARCH_DEFAULT_DAYS', 30),
        'max_days' => (int) env('TELEPHONY_SEARCH_MAX_DAYS', 366),
        // Borne le nombre de lignes sélectionnées dans une recherche groupée.
        'max_records' => (int) env('TELEPHONY_SEARCH_MAX_RECORDS', 50),
        // Borne le nombre d'appels retournés par fournisseur et par client.
        'max_results' => (int) env('TELEPHONY_SEARCH_MAX_RESULTS', 200),
    ],

    'recordings' => [
        // Disque privé (hors racine web publique).
        'disk' => env('TELEPHONY_RECORDING_DISK', 'local'),
        // Répertoire relatif dans le disque privé.
        'path' => trim((string) env('TELEPHONY_RECORDING_PATH', 'recordings'), '/'),
        // Taille maximale d'un fichier audio accepté (octets).
        'max_bytes' => (int) env('TELEPHONY_RECORDING_MAX_BYTES', 50 * 1024 * 1024),
        // Types MIME audio acceptés. `application/octet-stream` est toléré
        // pour les PBX qui ne fournissent pas de type fiable, jamais pour du
        // HTML/JS.
        'mime_types' => [
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/x-wav',
            'audio/wave',
            'audio/ogg',
            'audio/oga',
            'audio/mp4',
            'audio/x-m4a',
            'audio/aac',
            'audio/webm',
            'application/octet-stream',
        ],
        // Purge documentée : 0 = conservation sans limite.
        'retention_days' => (int) env('TELEPHONY_RECORDING_RETENTION_DAYS', 180),
    ],

    'kavkom' => [
        // L'ingestion webhook est la seule capacité vérifiée.
        'webhook_enabled' => (bool) env('KAVKOM_WEBHOOK_ENABLED', false),
        // Secret partagé envoyé par Kavkom dans un en-tête personnalisé.
        'webhook_secret' => env('KAVKOM_WEBHOOK_SECRET'),
        'webhook_header' => env('KAVKOM_WEBHOOK_HEADER', 'X-Kavkom-Webhook-Token'),
    ],

    'issabel' => [
        'enabled' => (bool) env('ISSABEL_ENABLED', false),
        // Connexion Laravel dédiée, en lecture seule, vers asteriskcdrdb.
        // Voir config/database.php (connection « issabel »).
        'cdr_connection' => env('ISSABEL_CDR_CONNECTION', 'issabel'),
        'cdr_table' => env('ISSABEL_CDR_TABLE', 'cdr'),
        // Colonnes optionnelles selon l'installation. Laisser vide si la
        // colonne n'existe pas : aucune valeur n'est inventée.
        'agent_column' => env('ISSABEL_CDR_AGENT_COLUMN'),
        'direction_column' => env('ISSABEL_CDR_DIRECTION_COLUMN'),
        // Répertoire privé des enregistrements, lisible par l'application.
        // Laisser vide si les fichiers ne sont pas accessibles : l'état
        // « récupération non prise en charge » sera affiché.
        'recording_root' => env('ISSABEL_RECORDING_ROOT'),
    ],

];
