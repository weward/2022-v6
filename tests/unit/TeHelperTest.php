<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DTApi\Helpers\TeHelper;

class TeHelperTest extends TestCase
{
    /**
     * Will return the same value as $due_time if less than or equal to 90 hours
     */
    public function test_difference_less_than_90_hours()
    {
        $created_at = Carbon::parse('2024-01-01 00:00:00');
        $due_time = $created_at->copy()->addHours(88);

        $expected = $due_time->copy()->format('Y-m-d H:i:s');

        $TeHelper = new TeHelper;
        $expiration = $TeHelper->willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $expiration);
    }

    /**
     * This is failing!
     * Will return the $created_at value plus 90 minutes if less than or equal to 24 hours
     */
    public function test_difference_less_than_or_equal_to_24_hours()
    {
        $created_at = Carbon::parse('2024-01-01 00:00:00');
        $due_time = $created_at->copy()->addHours(20);

        $expected = $created_at->copy()->addMinutes(90)->format('Y-m-d H:i:s');

        $TeHelper = new TeHelper;
        $expiration = $TeHelper->willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $expiration);
    }

    /**
     * This is failing!
     * Will return the $created_at value plus 16 hours if greater than 24 hours but less than or equal to 72 hours
     */
    public function test_difference_greater_than_24_hours_less_than_or_equal_72_hours()
    {
        $created_at = Carbon::parse('2024-01-01 00:00:00');
        $due_time = $created_at->copy()->addHours(50);

        $expected = $created_at->copy()->addHours(16)->format('Y-m-d H:i:s');

        $TeHelper = new TeHelper;
        $expiration = $TeHelper->willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $expiration);
    }

    /**
     * Will return the same value as $due_time minus 48 hours if greater than 90 hours
     */
    public function test_difference_greater_than_90_hours()
    {
        $created_at = Carbon::parse('2024-01-01 00:00:00');
        $due_time = $created_at->copy()->addHours(120);

        $expected = $due_time->copy()->subHours(48)->format('Y-m-d H:i:s');

        $TeHelper = new TeHelper;
        $expiration = $TeHelper->willExpireAt($due_time, $created_at);

        $this->assertEquals($expected, $expiration);
    }
}
