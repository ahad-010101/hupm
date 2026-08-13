<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit trail (FR-AUD-01).
 *
 * A NULL actor means the system acted — a scheduled job, not a person. That
 * distinction matters when explaining to a tenant why a late fee appeared at
 * 03:00: nobody chose to charge them, a rule did.
 *
 * Writes go through the query builder rather than an Eloquent model so that
 * the Immutable guard on the AuditLog model cannot be worked around by
 * accident, and so audit writes never fire model events of their own.
 */
class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $changes  A summary, not a full row copy.
     *                                         Never put bank data or a password here (I-5).
     */
    public function record(string $action, ?Model $subject = null, array $changes = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes === [] ? null : json_encode($changes, JSON_THROW_ON_ERROR),
            'ip_address' => $this->request?->ip(),
            'user_agent' => substr((string) $this->request?->userAgent(), 0, 500) ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Record a model change, capturing only what actually changed.
     *
     * Storing before/after for the dirty attributes alone keeps the log
     * readable and avoids copying an entire row — including columns that should
     * never be duplicated anywhere.
     */
    public function recordChange(string $action, Model $subject): void
    {
        $changes = [];

        foreach ($subject->getDirty() as $attribute => $new) {
            $changes[$attribute] = [
                'from' => $subject->getOriginal($attribute),
                'to' => $new,
            ];
        }

        $this->record($action, $subject, $changes);
    }
}
