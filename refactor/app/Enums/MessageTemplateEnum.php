<?php

namespace App\Enums;

enum MessageTemplateEnum: string
{
    case CUSTOMER_PHYSICAL_TYPE_ONLY = 'physical_only';
    case CUSTOMER_PHONE_TYPE_ONLY = 'phone_only';
    case BOTH = 'both';
    case NONE = 'none';

    /**
     * Analyse whether it's phone or physical; 
     *  if both = default to phone
     */
    public static function determine($physicalType, $phoneType): self
    {
        if ($physicalType == 'yes' && $phoneType == 'no') {
            // It's a physical job
            return self::CUSTOMER_PHYSICAL_TYPE_ONLY;
        } else if ($physicalType == 'no' && $phoneType == 'yes') {
            // It's a phone job
            return self::CUSTOMER_PHONE_TYPE_ONLY;
        } else if ($physicalType == 'yes' && $phoneType == 'yes') {
            // It's both, but should be handled as phone job
            return self::BOTH;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            return self::NONE;
        }
    }

    public function getMessageTemplate($physicalJobMessageTemplate, $phoneJobMessageTemplate): array
    {
        return match ($this) {
            self::CUSTOMER_PHYSICAL_TYPE_ONLY =>  $physicalJobMessageTemplate,
            self::CUSTOMER_PHONE_TYPE_ONLY, self::BOTH => $phoneJobMessageTemplate,
            self::NONE => ''
        };
    }

}