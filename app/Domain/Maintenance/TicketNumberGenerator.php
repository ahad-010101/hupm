<?php

namespace App\Domain\Maintenance;

use Illuminate\Support\Facades\DB;

/**
 * Sequential ticket numbers.  [AC-MNT-03]
 *
 * A human number, not a database id. "Ticket 1043" is what gets read down a
 * phone line and written on a work order, and it has to be short enough to say
 * and unique enough to trust.
 *
 * `SELECT … FOR UPDATE` on the counter row, inside the caller's transaction.
 * Two tenants submitting in the same second is rare with twenty-six residents
 * and inevitable over a few years; a race here produces two tickets with the
 * same number, which is the one thing the number exists to prevent.
 *
 * MAX(id)+1 would be the tempting shortcut and is exactly the bug: it reads
 * without locking, so two concurrent readers both see the same maximum.
 */
class TicketNumberGenerator
{
    private const COUNTER = 'maintenance_ticket';

    /** Starting above 1000 so the first real ticket does not look like a test row. */
    private const FIRST = 1001;

    private const PREFIX = 'MR-';

    public function next(): string
    {
        return DB::transaction(function () {
            $row = DB::table('counters')
                ->where('name', self::COUNTER)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                // First ever ticket. insertOrIgnore rather than insert: two
                // requests arriving before either has committed would otherwise
                // collide on the primary key.
                DB::table('counters')->insertOrIgnore([
                    'name' => self::COUNTER,
                    'value' => self::FIRST - 1,
                ]);

                $row = DB::table('counters')
                    ->where('name', self::COUNTER)
                    ->lockForUpdate()
                    ->first();
            }

            $next = max((int) $row->value + 1, self::FIRST);

            DB::table('counters')->where('name', self::COUNTER)->update(['value' => $next]);

            return self::PREFIX.$next;
        });
    }
}
