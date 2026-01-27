<?php
/**
 * Route Dispatch Reminder
 * - Creates and manages WH dispatch reminders after Route Confirmed
 * - Handles snooze and stop
 */

class RouteReminder {
    private PDO $db;
    private AuditLog $audit;

    const STATUS_ACTIVE = 'Active';
    const STATUS_SNOOZED = 'Snoozed';
    const STATUS_STOPPED = 'Stopped';

    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
    }

    /**
     * Create or reset reminder for a confirmed route
     */
    public function createOrReset(array $route, int $userId): array {
        $nextDue = $this->computeNextDue(new DateTimeImmutable(), $route['route_date']);

        $stmt = $this->db->prepare("
            INSERT INTO route_dispatch_reminders
                (route_id, plan_id, job_id, status, next_due_at, created_at)
            VALUES
                (:route_id, :plan_id, :job_id, :status, :next_due_at, NOW())
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                next_due_at = VALUES(next_due_at),
                last_notified_at = NULL,
                snooze_until = NULL,
                snooze_by = NULL,
                stopped_at = NULL,
                stopped_by = NULL,
                stop_reason = NULL,
                updated_at = NOW()
        ");

        $stmt->execute([
            'route_id' => $route['id'],
            'plan_id' => $route['plan_id'],
            'job_id' => $route['job_id'],
            'status' => self::STATUS_ACTIVE,
            'next_due_at' => $nextDue->format('Y-m-d H:i:s')
        ]);

        $this->audit->log(
            'reminder_create',
            'ROUTE',
            (int) $route['id'],
            null,
            ['status' => self::STATUS_ACTIVE, 'next_due_at' => $nextDue->format('Y-m-d H:i:s')]
        );

        return ['success' => true, 'next_due_at' => $nextDue->format('Y-m-d H:i:s')];
    }

    /**
     * Snooze reminder with preset options
     */
    public function snooze(int $routeId, string $optionKey, int $userId): array {
        $route = $this->getRoute($routeId);
        if (!$route) {
            return ['success' => false, 'error' => 'Route not found'];
        }
        if ($route['status'] !== 'Confirmed') {
            return ['success' => false, 'error' => 'สามารถ Snooze ได้เฉพาะ Route ที่ Confirmed เท่านั้น'];
        }

        if (!$this->getByRouteId($routeId)) {
            $this->createOrReset($route, $userId);
        }

        $now = new DateTimeImmutable();
        $snoozeUntil = $this->computeSnoozeUntil($optionKey, $now);
        $nextDue = $this->computeNextDue($snoozeUntil, $route['route_date']);

        $stmt = $this->db->prepare("
            UPDATE route_dispatch_reminders
            SET status = :status,
                snooze_until = :snooze_until,
                snooze_by = :snooze_by,
                next_due_at = :next_due_at
            WHERE route_id = :route_id
        ");
        $stmt->execute([
            'status' => self::STATUS_SNOOZED,
            'snooze_until' => $snoozeUntil->format('Y-m-d H:i:s'),
            'snooze_by' => $userId,
            'next_due_at' => $nextDue->format('Y-m-d H:i:s'),
            'route_id' => $routeId
        ]);

        $this->audit->log(
            'reminder_snooze',
            'ROUTE',
            $routeId,
            null,
            [
                'snooze_until' => $snoozeUntil->format('Y-m-d H:i:s'),
                'next_due_at' => $nextDue->format('Y-m-d H:i:s'),
                'option' => $optionKey
            ]
        );

        return ['success' => true, 'next_due_at' => $nextDue->format('Y-m-d H:i:s')];
    }

    /**
     * Stop reminder for a route
     */
    public function stop(int $routeId, string $reason, int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE route_dispatch_reminders
            SET status = :status,
                stopped_at = NOW(),
                stopped_by = :stopped_by,
                stop_reason = :stop_reason
            WHERE route_id = :route_id
        ");
        $stmt->execute([
            'status' => self::STATUS_STOPPED,
            'stopped_by' => $userId,
            'stop_reason' => $reason,
            'route_id' => $routeId
        ]);

        $this->audit->log(
            'reminder_stop',
            'ROUTE',
            $routeId,
            null,
            ['status' => self::STATUS_STOPPED, 'reason' => $reason]
        );
    }

    /**
     * Stop all reminders for a job
     */
    public function stopByJobId(int $jobId, string $reason, int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE route_dispatch_reminders
            SET status = :status,
                stopped_at = NOW(),
                stopped_by = :stopped_by,
                stop_reason = :stop_reason
            WHERE job_id = :job_id AND status != :status
        ");
        $stmt->execute([
            'status' => self::STATUS_STOPPED,
            'stopped_by' => $userId,
            'stop_reason' => $reason,
            'job_id' => $jobId
        ]);

        $this->audit->log(
            'reminder_stop',
            'JOB',
            $jobId,
            null,
            ['status' => self::STATUS_STOPPED, 'reason' => $reason]
        );
    }

    /**
     * Get due reminders for processing
     */
    public function getDueReminders(DateTimeImmutable $now): array {
        $stmt = $this->db->prepare("
            SELECT rdr.*, r.route_number, r.route_date, r.status AS route_status,
                   j.job_number, j.status AS job_status, j.owner_planner_id
            FROM route_dispatch_reminders rdr
            JOIN routes r ON rdr.route_id = r.id
            JOIN jobs j ON rdr.job_id = j.id
            WHERE rdr.status IN ('Active','Snoozed')
              AND rdr.next_due_at <= :now
        ");
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    }

    /**
     * Mark reminder notified and schedule next due
     */
    public function markNotified(int $reminderId, DateTimeImmutable $nextDue, DateTimeImmutable $now): void {
        $stmt = $this->db->prepare("
            UPDATE route_dispatch_reminders
            SET status = :status,
                last_notified_at = :last_notified_at,
                next_due_at = :next_due_at
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => self::STATUS_ACTIVE,
            'last_notified_at' => $now->format('Y-m-d H:i:s'),
            'next_due_at' => $nextDue->format('Y-m-d H:i:s'),
            'id' => $reminderId
        ]);
    }

    /**
     * Compute next reminder time (07:30 then every 2 hours)
     */
    public function computeNextDue(DateTimeImmutable $from, string $routeDate): DateTimeImmutable {
        $routeDay = new DateTimeImmutable($routeDate . ' 07:30:00');
        $fromDate = $from->format('Y-m-d');

        if ($from < $routeDay) {
            return $routeDay;
        }

        // If past route day, use today's 07:30 as base
        $baseDate = $fromDate < $routeDate ? $routeDate : $fromDate;
        $base = new DateTimeImmutable($baseDate . ' 07:30:00');

        if ($from < $base) {
            return $base;
        }

        $diffSeconds = $from->getTimestamp() - $base->getTimestamp();
        $slot = (int) floor($diffSeconds / (2 * 3600)) + 1;
        $next = $base->modify('+' . ($slot * 2) . ' hours');

        // Cap to next day 07:30 if we cross midnight
        if ($next->format('Y-m-d') !== $base->format('Y-m-d')) {
            $next = (new DateTimeImmutable($base->format('Y-m-d') . ' 07:30:00'))->modify('+1 day');
        }

        return $next;
    }

    /**
     * Compute snooze-until based on option key
     */
    public function computeSnoozeUntil(string $optionKey, DateTimeImmutable $now): DateTimeImmutable {
        $map = [
            '30m' => 30,
            '2h' => 120,
            '4h' => 240,
            '8h' => 480
        ];

        if ($optionKey === 'next_day') {
            $nextDay = new DateTimeImmutable($now->format('Y-m-d') . ' 07:30:00');
            return $nextDay->modify('+1 day');
        }

        $minutes = $map[$optionKey] ?? 30;
        return $now->modify('+' . $minutes . ' minutes');
    }

    private function getRoute(int $routeId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.*, p.job_id
            FROM routes r
            JOIN plans p ON r.plan_id = p.id
            WHERE r.id = ?
        ");
        $stmt->execute([$routeId]);
        return $stmt->fetch() ?: null;
    }

    private function getByRouteId(int $routeId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM route_dispatch_reminders WHERE route_id = ?");
        $stmt->execute([$routeId]);
        return $stmt->fetch() ?: null;
    }
}
