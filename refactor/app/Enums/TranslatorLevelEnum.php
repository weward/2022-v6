<?php

namespace App\Enums;

enum TranslatorLevelEnum: string
{
    case YES = 'yes';
    case BOTH = 'both';
    case LAW = 'law';
    case N_LAW = 'n_law';
    case HEALTH = 'health';
    case N_HEALTH = 'n_health';
    case NORMAL = 'normal';

    public function levels(): array
    {
        return match ($this) {
            self::YES, self::BOTH => [ // not sure if both applies here (based from the original)
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care',
            ],
            self::LAW, self::N_LAW => [
                'Certified with specialisation in law',
            ],
            self::HEALTH, self::N_HEALTH => [
                'Certified with specialisation in health care',
            ],
            self::NORMAL, self::BOTH => [
                'Layman',
                'Read Translation courses'
            ],
        };
    }
}
