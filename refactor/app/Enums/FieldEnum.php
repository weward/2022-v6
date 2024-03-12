<?php

namespace App\Enums;

enum FieldEnum: string
{
    case FROM_LANGUAGE_ID = 'from_language_id';
    case DUE_DATE = 'due_date';
    case DUE_TIME = 'due_time';
    case NO_PHONE_NO_PHYSICAL = 'customer_phone_type';
    case DURATION = 'duration';

    public function response(): array
    {
        return match ($this) {
            self::FROM_LANGUAGE_ID => [
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => $this->value,
            ],
            self::DUE_DATE => [
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => $this->value,
            ],
            self::DUE_TIME => [
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => $this->value,
            ],
            self::CUSTOMER_PHONE_TYPE => [
                'status' => 'fail',
                'message' => 'Du måste göra ett val här',
                'field_name' => $this->value,
            ],
            self::DURATION => [
                'status' => 'fail',
                'message' => 'Du måste fylla in alla fält',
                'field_name' => $this->value,
            ],

        };
    }
}