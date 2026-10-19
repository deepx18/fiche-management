<?php

namespace App\Services\Google;

/**
 * Lecture d'une plage Google Sheets.
 *
 * Abstraction volontairement minimale : le synchroniseur ne connaît que cette
 * interface, ce qui permet de tester l'import sans identifiants Google ni
 * accès réseau (voir FakeSheetsReader dans les tests).
 */
interface SheetsReader
{
    /**
     * Lit la plage configurée (config('production.range')).
     *
     * @return list<list<string|null>> lignes brutes, chaque cellule étant une
     *                                 chaîne (zéros initiaux préservés) ou null
     *
     * @throws GoogleSheetsException configuration manquante, identifiants
     *                               refusés, permission ou erreur API
     */
    public function rows(): array;
}
