<?php

namespace App\Services\Production;

use RuntimeException;

/**
 * Une valeur de cellule est invalide (date, montant, longueur).
 *
 * Les messages sont sûrs à afficher : ils décrivent la règle attendue et
 * n'incluent jamais la valeur d'origine (donnée personnelle / montant).
 */
class ProductionValueException extends RuntimeException
{
    public static function invalidDate(): self
    {
        return new self('Date invalide (formats acceptés : jj/mm/aaaa ou aaaa-mm-jj).');
    }

    public static function invalidAmount(): self
    {
        return new self('Montant invalide (nombre attendu, séparateur « . » ou « , », 4 décimales maximum).');
    }

    public static function tooLong(int $max): self
    {
        return new self('Valeur trop longue ('.$max.' caractères maximum).');
    }
}
