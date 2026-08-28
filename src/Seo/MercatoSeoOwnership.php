<?php
namespace ProcessWire;

final class MercatoSeoOwnership {
    public const MERCATO = 'mercato';
    public const ICHIBAN = 'ichiban';

    public static function resolve(bool $ichibanInstalled): string {
        return $ichibanInstalled ? self::ICHIBAN : self::MERCATO;
    }
}
