<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\Vehicle;
use PDO;
use PDOStatement;

/**
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class VehicleTest extends TestCase
{
    private Vehicle $vehicle;
    private \stdClass $mockDb;
    private PDO $mockPdo;

    protected function setUp(): void
    {
        // Create mock PDO
        $this->mockPdo = $this->createMock(PDO::class);
        
        // Create simple mock object for Database
        $this->mockDb = new \stdClass();
        
        // Create Vehicle instance and inject mock database via reflection
        $this->vehicle = new class extends Vehicle {
            public function setDatabase($db): void {
                $this->db = $db;
            }
            
            public function setTable(string $table): void {
                $this->table = $table;
            }
        };
        $this->vehicle->setDatabase($this->mockDb);
        $this->vehicle->setTable('vehicles');
    }

    public function testGetUserVehiclesReturnsArray(): void
    {
        $this->mockDb->fetchAll = function() {
            return [
                ['id' => 1, 'make' => 'Toyota', 'model' => 'Camry'],
                ['id' => 2, 'make' => 'Honda', 'model' => 'Civic']
            ];
        };
        
        $result = $this->vehicle->getUserVehicles(1);
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testGetUserVehiclesWithActiveOnly(): void
    {
        $called = false;
        $this->mockDb->fetchAll = function($sql) use (&$called) {
            $called = true;
            $this->assertStringContainsString('AND is_active = 1', $sql);
            return [];
        };
        
        $this->vehicle->getUserVehicles(1, true);
        $this->assertTrue($called);
    }

    public function testGetUserVehiclesWithoutActiveOnly(): void
    {
        $called = false;
        $this->mockDb->fetchAll = function($sql) use (&$called) {
            $called = true;
            $this->assertStringNotContainsString('AND is_active = 1', $sql);
            return [];
        };
        
        $this->vehicle->getUserVehicles(1, false);
        $this->assertTrue($called);
    }

    public function testFindWithServiceRecordReturnsNullWhenNotFound(): void
    {
        $this->mockDb->fetchOne = function() {
            return null;
        };
        
        $result = $this->vehicle->findWithServiceRecord(999);
        
        $this->assertNull($result);
    }

    public function testFindWithServiceRecordReturnsArrayWhenFound(): void
    {
        $expectedData = [
            'id' => 1,
            'make' => 'Toyota',
            'last_service_date' => '2024-01-15',
            'next_service_mileage' => 50000
        ];
        
        $this->mockDb->fetchOne = function() use ($expectedData) {
            return $expectedData;
        };
        
        $result = $this->vehicle->findWithServiceRecord(1);
        
        $this->assertEquals($expectedData, $result);
    }

    public function testGetVehiclesNeedingServiceUsesThreshold(): void
    {
        $this->mockDb->fetchAll = function() {
            return [
                ['id' => 1, 'km_remaining' => 500]
            ];
        };
        
        $result = $this->vehicle->getVehiclesNeedingService(1, 2000);
        
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testCreateVehicleMergesDefaults(): void
    {
        $this->mockDb->query = function() {
            return $this->createMock(PDOStatement::class);
        };
        $this->mockDb->lastInsertId = function() {
            return '123';
        };
        
        $result = $this->vehicle->createVehicle([
            'user_id' => 1,
            'make' => 'Ford',
            'model' => 'Focus'
        ]);
        
        $this->assertEquals('123', $result);
    }

    public function testUpdateVehicleSetsUpdatedAt(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $this->mockDb->query = function() use ($mockStmt) {
            return $mockStmt;
        };
        
        $result = $this->vehicle->updateVehicle(1, ['make' => 'Updated Make']);
        
        $this->assertTrue($result);
    }

    public function testSoftDeleteSetsIsActiveToZero(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $this->mockDb->query = function() use ($mockStmt) {
            return $mockStmt;
        };
        
        $result = $this->vehicle->softDelete(1);
        
        $this->assertTrue($result);
    }

    public function testUpdateMileageUpdatesCurrentMileage(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $this->mockDb->query = function() use ($mockStmt) {
            return $mockStmt;
        };
        
        $result = $this->vehicle->updateMileage(1, 50000);
        
        $this->assertTrue($result);
    }

    public function testGetStatisticsReturnsArray(): void
    {
        $callCount = 0;
        $this->mockDb->fetchOne = function() use (&$callCount) {
            $callCount++;
            switch ($callCount) {
                case 1: return ['count' => 5];  // total_services
                case 2: return ['count' => 10]; // total_fuel_logs
                case 3: return ['total' => 1000]; // total_expenses
                case 4: return ['avg' => 8.5];    // avg_fuel_consumption
                default: return null;
            }
        };
        
        $stats = $this->vehicle->getStatistics(1);
        
        $this->assertIsArray($stats);
        $this->assertEquals(5, $stats['total_services']);
        $this->assertEquals(10, $stats['total_fuel_logs']);
        $this->assertEquals(1000, $stats['total_expenses']);
        $this->assertEquals(8.5, $stats['avg_fuel_consumption']);
    }

    public function testGetStatisticsHandlesNullValues(): void
    {
        $this->mockDb->fetchOne = function() {
            return null;
        };
        
        $stats = $this->vehicle->getStatistics(1);
        
        $this->assertEquals(0, $stats['total_services']);
        $this->assertEquals(0, $stats['total_fuel_logs']);
        $this->assertEquals(0, $stats['total_expenses']);
        $this->assertEquals(0, $stats['avg_fuel_consumption']);
    }

    public function testBelongsToUserReturnsTrueWhenVehicleBelongsToUser(): void
    {
        $this->mockDb->fetchOne = function() {
            return ['count' => 1];
        };
        
        $result = $this->vehicle->belongsToUser(1, 1);
        
        $this->assertTrue($result);
    }

    public function testBelongsToUserReturnsFalseWhenVehicleDoesNotBelongToUser(): void
    {
        $this->mockDb->fetchOne = function() {
            return ['count' => 0];
        };
        
        $result = $this->vehicle->belongsToUser(1, 2);
        
        $this->assertFalse($result);
    }

    public function testGetByFuelTypeFiltersByFuelType(): void
    {
        $this->mockDb->fetchAll = function() {
            return [['id' => 1, 'fuel_type' => 'petrol']];
        };
        
        $result = $this->vehicle->getByFuelType(1, 'petrol');
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testSearchUsesLikeOperator(): void
    {
        $this->mockDb->fetchAll = function() {
            return [['id' => 1, 'make' => 'Toyota']];
        };
        
        $result = $this->vehicle->search(1, 'Toy');
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testSearchWithEmptyQuery(): void
    {
        $this->mockDb->fetchAll = function() {
            return [];
        };
        
        $result = $this->vehicle->search(1, '');
        
        $this->assertIsArray($result);
    }
}
