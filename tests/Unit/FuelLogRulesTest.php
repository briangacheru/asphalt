<?php

namespace Tests\Unit;

use App\Services\FuelLogRules;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class FuelLogRulesTest extends TestCase
{
    /** A PDO whose "records already on that day" count query returns $existingOnDay. */
    private function pdoWithDayCount(int $existingOnDay): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($existingOnDay);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return $pdo;
    }

    public function testAcceptsAnEntryAtTheMinimumWithRoomInTheDay(): void
    {
        $this->assertNull(FuelLogRules::violation($this->pdoWithDayCount(0), 1, '2026-09-01', 500.0));
    }

    public function testRejectsATotalBelowTheMinimum(): void
    {
        $message = FuelLogRules::violation($this->pdoWithDayCount(0), 1, '2026-09-01', 499.99);
        $this->assertNotNull($message);
        $this->assertStringContainsString('at least 500', $message);
    }

    public function testRejectsWhenTheDayIsAlreadyFull(): void
    {
        $full = FuelLogRules::MAX_RECORDS_PER_VEHICLE_PER_DAY;
        $message = FuelLogRules::violation($this->pdoWithDayCount($full), 1, '2026-09-01', 1000.0);
        $this->assertNotNull($message);
        $this->assertStringContainsString('at most ' . $full, $message);
    }

    public function testAllowsTheLastSlotOfTheDay(): void
    {
        $almostFull = FuelLogRules::MAX_RECORDS_PER_VEHICLE_PER_DAY - 1;
        $this->assertNull(FuelLogRules::violation($this->pdoWithDayCount($almostFull), 1, '2026-09-01', 1000.0));
    }

    public function testEditingAnOldSmallEntryWithoutChangingTheTotalIsAllowed(): void
    {
        $existing = ['id' => 5, 'vehicle_id' => 1, 'fill_date' => '2026-09-01', 'total_cost' => '320.00'];
        $this->assertNull(FuelLogRules::violation($this->pdoWithDayCount(0), 1, '2026-09-01', 320.0, $existing));
    }

    public function testChangingAnOldSmallEntryToAnotherSmallTotalIsRejected(): void
    {
        $existing = ['id' => 5, 'vehicle_id' => 1, 'fill_date' => '2026-09-01', 'total_cost' => '320.00'];
        $this->assertNotNull(FuelLogRules::violation($this->pdoWithDayCount(0), 1, '2026-09-01', 350.0, $existing));
    }

    public function testEditingWithinAnAlreadyFullDayIsAllowedWhenVehicleAndDateAreUnchanged(): void
    {
        $full = FuelLogRules::MAX_RECORDS_PER_VEHICLE_PER_DAY;
        $existing = ['id' => 5, 'vehicle_id' => 1, 'fill_date' => '2026-09-01', 'total_cost' => '900.00'];
        $this->assertNull(FuelLogRules::violation($this->pdoWithDayCount($full), 1, '2026-09-01', 900.0, $existing));
    }

    public function testMovingAnEntryOntoAFullDayIsRejected(): void
    {
        $full = FuelLogRules::MAX_RECORDS_PER_VEHICLE_PER_DAY;
        $existing = ['id' => 5, 'vehicle_id' => 1, 'fill_date' => '2026-08-30', 'total_cost' => '900.00'];
        $this->assertNotNull(FuelLogRules::violation($this->pdoWithDayCount($full), 1, '2026-09-01', 900.0, $existing));
    }
}
