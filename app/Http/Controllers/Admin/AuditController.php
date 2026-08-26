<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, made readable.  [FR-AUD-01, API-ADM-41, UI §3, WP-29]
 *
 * Writing happens everywhere via {@see AuditLogger}; this is the
 * only thing that reads it. Deliberately read-only: there is no update route,
 * no delete route, and no export — the model refuses both mutations anyway
 * (AC-AUD-02), but a route that does not exist cannot be reached by a bug
 * either.
 *
 * The filter options are queried from the table rather than kept in a hand-
 * maintained catalogue. Roughly seventy action strings exist across the domain
 * services and more arrive with every work package; a list someone has to
 * remember to update is a list that is wrong by the time it matters. Asking the
 * data what it contains cannot go stale.
 */
class AuditController extends Controller
{
    /** Big enough to scan a day's activity, small enough to render on a phone. */
    private const PER_PAGE = 50;

    public function __construct(private readonly BusinessCalendar $calendar) {}

    /** API-ADM-41. */
    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($request->integer('actor'), fn (Builder $q, int $id) => $q->where('user_id', $id))
            // "system" is a filter in its own right, not the absence of one:
            // "what did the nightly jobs do" is the question you ask when a
            // tenant wants to know why a late fee appeared at 03:00, and a NULL
            // actor is precisely that answer.
            ->when($request->string('actor')->value() === 'system', fn (Builder $q) => $q->whereNull('user_id'))
            ->when($request->string('action')->value(), fn (Builder $q, string $a) => $q->where('action', $a))
            ->when($request->string('subject_type')->value(), fn (Builder $q, string $t) => $q->where('subject_type', $t))
            ->when($request->integer('subject_id'), fn (Builder $q, int $id) => $q->where('subject_id', $id))
            ->when($this->boundary($request->string('from')->value()),
                fn (Builder $q, CarbonImmutable $at) => $q->where('created_at', '>=', $at))
            ->when($this->boundary($request->string('to')->value(), endOfDay: true),
                fn (Builder $q, CarbonImmutable $at) => $q->where('created_at', '<=', $at))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                // Rendered in the company timezone by the page, but sent as an
                // instant so the client is not guessing (D-07).
                'at' => $log->created_at?->toIso8601String(),
                'actor' => $log->user?->name,
                'actor_id' => $log->user_id,
                'action' => $log->action,
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_id' => $log->subject_id,
                'changes' => $log->changes,
                'ip_address' => $log->ip_address,
            ]);

        return Inertia::render('Admin/Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only(['actor', 'action', 'subject_type', 'subject_id', 'from', 'to']),
            'actions' => $this->actionsInUse(),
            'subjectTypes' => $this->subjectTypesInUse(),
            'actors' => $this->actorsInUse(),
            'timezone' => $this->calendar->timezone(),
        ]);
    }

    /**
     * A filter date, resolved as a business date.
     *
     * `created_at` is stored UTC; the person typing "26 August" means the
     * Georgia day (D-07). Between midnight and 04:00 UTC those are different
     * dates, which is exactly when the overnight jobs run — the rows most
     * likely to be searched for are the ones a naive comparison would miss.
     */
    private function boundary(string $date, bool $endOfDay = false): ?CarbonImmutable
    {
        if ($date === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($date, $this->calendar->timezone());
        } catch (\Throwable) {
            // A hand-edited query string is not worth a 500.
            return null;
        }

        return ($endOfDay ? $at->endOfDay() : $at->startOfDay())->utc();
    }

    /**
     * Every action string that actually appears, grouped by its prefix.
     *
     * @return array<string, list<string>>
     */
    private function actionsInUse(): array
    {
        return DB::table('audit_logs')
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->groupBy(fn (string $action) => str_contains($action, '.')
                ? strtok($action, '.')
                : 'other')
            ->map(fn ($group) => $group->values()->all())
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function subjectTypesInUse(): array
    {
        return DB::table('audit_logs')
            ->select('subject_type')
            ->distinct()
            ->whereNotNull('subject_type')
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->map(fn (string $type) => ['value' => $type, 'label' => class_basename($type)])
            ->all();
    }

    /**
     * Only people who have actually done something appear.
     *
     * A dropdown of every user in the system offers filters that return nothing;
     * this one only offers questions that have an answer.
     *
     * @return list<array{value: string, label: string}>
     */
    private function actorsInUse(): array
    {
        return DB::table('audit_logs')
            ->join('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('users.id', 'users.name')
            ->distinct()
            ->orderBy('users.name')
            ->get()
            ->map(fn ($row) => ['value' => (string) $row->id, 'label' => $row->name])
            ->all();
    }
}
