<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The status shown for each instalment, derived from the stored status
 * (UNPAID, PAID, SETTLED), the due date and today. OVERDUE, DUE and UPCOMING
 * are never stored: they change as time passes, not because anything happened,
 * so a stored copy would need a nightly job and would be wrong whenever that
 * job hadn't run yet.
 *
 * - PAID / SETTLED: as stored.
 * - OVERDUE: UNPAID and due before today.
 * - DUE: the oldest UNPAID instalment that isn't overdue.
 * - UPCOMING: every other UNPAID instalment.
 */
final class InstalmentStatus
{
    /**
     * @param  iterable<object|array<string, mixed>>  $rows  instalments with status, due_date and instalment_number
     * @return list<array<string, mixed>> the rows as arrays, ordered by instalment_number, with display_status added
     */
    public static function for(iterable $rows, CarbonInterface $today): array
    {
        $rows = collect($rows)->map(fn ($row) => (array) $row)->sortBy('instalment_number')->values();
        $todayString = $today->toDateString();
        $dueAssigned = false;

        return $rows->map(function (array $row) use ($todayString, &$dueAssigned) {
            if ($row['status'] !== 'UNPAID') {
                $display = $row['status'];
            } elseif ((string) $row['due_date'] < $todayString) {
                $display = 'OVERDUE';
            } elseif (! $dueAssigned) {
                $display = 'DUE';
                $dueAssigned = true;
            } else {
                $display = 'UPCOMING';
            }

            return $row + ['display_status' => $display];
        })->all();
    }
}
