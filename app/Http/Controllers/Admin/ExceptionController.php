<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\ExceptionFeed;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The exceptions list, and acknowledging one.  [FR-ADM-01, AC-ADM-02]
 *
 * A screen of its own as well as a panel, because the badge in the top bar
 * follows the admin around every page and has to lead somewhere — and because
 * the dashboard shows the first handful while this shows all of them.
 */
class ExceptionController extends Controller
{
    public function __construct(private readonly ExceptionFeed $exceptions) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Exceptions', [
            'exceptions' => $this->exceptions->items(),
            'summary' => $this->exceptions->summary(),
        ]);
    }

    /**
     * "I have seen this."  [AC-ADM-02]
     *
     * Only the two terminal kinds can be acknowledged; the service refuses the
     * rest, and that refusal reaches the screen as a validation error rather
     * than a 500, because a stale tab is a normal thing to have open.
     */
    public function acknowledge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', ExceptionFeed::ACKNOWLEDGEABLE)],
            'subject_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'kind.in' => 'That kind of exception clears when it is resolved, not when it is acknowledged.',
        ]);

        try {
            $this->exceptions->acknowledge(
                $validated['kind'],
                (int) $validated['subject_id'],
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['kind' => $e->getMessage()]);
        }

        return back()->with('status', 'Acknowledged. It will not appear on the panel again.');
    }
}
