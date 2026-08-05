<?php

namespace App\Services;

/**
 * Decides which of a user's vehicles need a mileage-update nudge. A vehicle is
 * "due" once we're within 3 days of month-end and it hasn't had a mileage
 * update this month, and it STAYS due (carries over the month boundary) until
 * a fresh reading lands via manual update, fuel log, expense, or service.
 */
class MileageReminderService
{
    public static function vehiclesNeedingUpdate(\PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT v.id, v.make, v.model,
                    (SELECT MAX(x.d) FROM (
                        SELECT MAX(log_date) AS d FROM mileage_log WHERE vehicle_id = v.id
                        UNION ALL
                        SELECT MAX(fill_date) FROM fuel_log WHERE vehicle_id = v.id
                        UNION ALL
                        SELECT MAX(expense_date) FROM expenses WHERE vehicle_id = v.id AND mileage IS NOT NULL
                    ) x) AS last_mileage_date
                FROM vehicles v
                WHERE v.user_id = ? AND v.is_active = 1
            ");
            $stmt->execute([$userId]);
            $vehicles = $stmt->fetchAll();
        } catch (\PDOException $e) {
            // expenses.mileage may not exist yet on environments that haven't
            // picked up the schema change — silently skip the reminder.
            return [];
        }

        $today = new \DateTime();
        $daysLeftInMonth = (int) $today->format('t') - (int) $today->format('j');
        $firstOfThisMonth = new \DateTime($today->format('Y-m-01'));

        $needing = [];
        foreach ($vehicles as $v) {
            $lastDate = $v['last_mileage_date'] ? new \DateTime($v['last_mileage_date']) : null;

            if ($lastDate === null) {
                $needsUpdate = $daysLeftInMonth <= 3;
            } else {
                $updatedThisMonth = $lastDate->format('Y-m') === $today->format('Y-m');
                $needsUpdate = !$updatedThisMonth && ($daysLeftInMonth <= 3 || $lastDate < $firstOfThisMonth);
            }

            if ($needsUpdate) {
                $needing[] = $v;
            }
        }

        return $needing;
    }
}
