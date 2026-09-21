<?php

namespace App\Services;

/**
 * Guard rails for fuel log entries, shared by the web pages (fuel-log.php)
 * and the JSON API so neither path can bypass the other.
 *
 *  - At most MAX_RECORDS_PER_VEHICLE_PER_DAY fill-ups per vehicle per fill date.
 *  - A fill-up's total must be at least MIN_TOTAL_AMOUNT (in the user's own
 *    currency; amounts are plain decimals with no FX conversion).
 *
 * When editing an existing record, pass it as $existing so historic data that
 * predates these rules stays editable: the minimum only applies if the total
 * changes, and the daily limit only if the vehicle or date changes.
 */
class FuelLogRules
{
    public const MAX_RECORDS_PER_VEHICLE_PER_DAY = 3;
    public const MIN_TOTAL_AMOUNT = 500;

    /**
     * @param array|null $existing The record being edited: id, vehicle_id, fill_date, total_cost.
     * @return string|null A user-facing message if the entry breaks a rule, otherwise null.
     */
    public static function violation(\PDO $pdo, int $vehicleId, string $fillDate, float $totalCost, ?array $existing = null): ?string
    {
        $totalCost = round($totalCost, 2);

        $totalUnchanged = $existing !== null && abs($totalCost - round((float) $existing['total_cost'], 2)) < 0.005;
        if ($totalCost < self::MIN_TOTAL_AMOUNT && !$totalUnchanged) {
            return 'The total fuel amount must be at least ' . number_format(self::MIN_TOTAL_AMOUNT) . '.';
        }

        $sameSlot = $existing !== null
            && (int) $existing['vehicle_id'] === $vehicleId
            && substr((string) $existing['fill_date'], 0, 10) === $fillDate;
        if (!$sameSlot) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_log WHERE vehicle_id = ? AND DATE(fill_date) = ?");
            $stmt->execute([$vehicleId, $fillDate]);
            if ((int) $stmt->fetchColumn() >= self::MAX_RECORDS_PER_VEHICLE_PER_DAY) {
                return 'Limit reached: a vehicle can have at most ' . self::MAX_RECORDS_PER_VEHICLE_PER_DAY
                    . ' fuel records per day, and this vehicle already has that many on ' . $fillDate . '.';
            }
        }

        return null;
    }
}
