<?php

namespace App\Enums;

enum TranslatorTypeEnum: string
{
    case PROFESSIONAL = 'professional';
    case RWS_TRANSLATOR = 'rwstranslator';
    case VOLUNTEER = 'volunteer';

    case PAID = 'paid';
    case RWS = 'rws';
    case UNPAID = 'unpaid';
    
    public function type(): string
    {
        return match ($this) {
            self::PROFESSIONAL => self::PAID->value,
            self::RWS_TRANSLATOR => self::RWS->value,
            self::VOLUNTEER => self::UNPAID->value,
        };
    }
    
    public function translatorType(): string
    {
        return match ($this) {
            self::PAID => self::PROFESSIONAL->value,
            self::RWS => self::RWS_TRANSLATOR->value,
            self::UNPAID => self::VOLUNTEER->value,
        };
    }
}
