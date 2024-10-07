<?php
namespace DTApi\Unit\Helpers;

use Carbon\Carbon;
use DTApi\Helpers\TeHelper;
use PHPUnit\Framework\TestCase;

class TeHelpersTests extends TestCase
{
    public function testWillExpireAtWithin90Minutes()
    {
        $due_time = Carbon::now()->addHours(1)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($due_time, $result);
    }

    public function testWillExpireAtWithin24Hours()
    {
        $due_time = Carbon::now()->addHours(8)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expectedExpiration = Carbon::now()->addMinutes(90)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expectedExpiration, $result);
    }

    public function testWillExpireAtWithin72Hours()
    {
        $due_time = Carbon::now()->addHours(48)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expectedExpiration = Carbon::now()->addHours(16)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expectedExpiration, $result);
    }

    public function testWillExpireAtMoreThan72Hours()
    {
        $due_time = Carbon::now()->addDays(6)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expectedExpiration = Carbon::now()->addDays(6)->subHours(48)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expectedExpiration, $result);
    }

    public function testWillExpireAt90Minutes()
    {
        $due_time = Carbon::now()->addHours(1.5)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($due_time, $result);
    }

    public function testWillExpireAt24Hours()
    {
        $due_time = Carbon::now()->addHours(24)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expectedExpiration = Carbon::now()->addMinutes(90)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expectedExpiration, $result);
    }

    public function testWillExpireAt72Hours()
    {
        $due_time = Carbon::now()->addHours(72)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expectedExpiration = Carbon::now()->addHours(16)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expectedExpiration, $result);
    }

    public function testNegativeDifference()
    {
        $due_time = Carbon::now()->subHours(1)->format('Y-m-d H:i:s');
        $created_at = Carbon::now()->format('Y-m-d H:i:s');

        $expectedExpiration = Carbon::now()->subHours(48)->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($due_time, $created_at);

        $this->assertEquals($expectedExpiration, $result);
    }
}