<?php

namespace App\Enums;

enum JobCertifiedEnum: string
{
    case BOTH = 'both';
    case YES = 'yes';
    case N_HEALTH = 'n_health';
    case LAW = 'law';
    case N_LAW = 'n_law';

    public function label(): array
    {
        return match ($this) {
            self::BOTH => [
                'Godkänd tolk',
                'Auktoriserad',
            ],
            self::YES => [
                'Auktoriserad'
            ],
            self::N_HEALTH => [
                'Sjukvårdstolk',
            ],
            self::LAW, self::N_LAW => [
                'Rätttstolk',
            ],
            default => [
                $this->value
            ],
        };
    }
}
