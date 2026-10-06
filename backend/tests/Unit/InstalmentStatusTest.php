<?php

use App\Support\InstalmentStatus;
use Carbon\CarbonImmutable;

function instalment(int $number, string $dueDate, string $status = 'UNPAID'): array
{
    return ['instalment_number' => $number, 'due_date' => $dueDate, 'status' => $status];
}

it('derives paid, settled, overdue, due and upcoming', function () {
    $rows = InstalmentStatus::for([
        instalment(5, '2026-12-01', 'SETTLED'),
        instalment(1, '2026-07-01', 'PAID'),
        instalment(2, '2026-08-01'),
        instalment(3, '2026-09-01'),
        instalment(4, '2026-10-05'),
        (object) instalment(6, '2026-11-01'),
    ], CarbonImmutable::parse('2026-10-05'));

    expect(array_column($rows, 'display_status', 'instalment_number'))->toBe([
        1 => 'PAID',
        2 => 'OVERDUE',
        3 => 'OVERDUE',
        4 => 'DUE', // due today is not overdue yet
        5 => 'SETTLED',
        6 => 'UPCOMING',
    ]);
});

it('marks the oldest unpaid instalment as due when nothing is overdue', function () {
    $rows = InstalmentStatus::for([instalment(1, '2026-11-05'), instalment(2, '2026-12-05')], CarbonImmutable::parse('2026-10-05'));

    expect(array_column($rows, 'display_status'))->toBe(['DUE', 'UPCOMING']);
});
