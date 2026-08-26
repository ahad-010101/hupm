<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /health`.  [API-PUB-07, DEVIATION D-06]
 *
 * Two audiences, one URL.
 *
 * An uptime monitor gets a bare `ok` or `degraded` and the matching status
 * code — enough to page somebody, and nothing an attacker can use. The spec has
 * this endpoint returning last scheduler run, last reconciliation and queue
 * depth to anyone who asks; D-06 puts that behind a token, because publishing
 * exactly when the nightly jobs run and how far behind they are is a map of
 * when the system is least attended.
 *
 * Lives in the public route file, which carries **no Inertia middleware**
 * (D-05), so this response cannot pick up shared props on its way out.
 *
 * The status code carries the answer as well as the body: 200 healthy, 503
 * degraded. A monitor configured only on HTTP status still works, and one that
 * parses JSON gets the same verdict — they cannot disagree.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, SystemHealth $health): JsonResponse
    {
        $report = $health->report();
        $code = $report['status'] === 'ok' ? 200 : 503;

        if (! $this->holdsToken($request)) {
            return response()->json(['status' => $report['status']], $code);
        }

        return response()->json($report, $code);
    }

    /**
     * Fails closed: no configured token means no detailed response, ever.
     *
     * The header is the documented way in. The query parameter is accepted
     * because shared-hosting monitors frequently cannot send headers, but it
     * lands in the host's access log, so it is the second choice rather than
     * the convenient one.
     */
    private function holdsToken(Request $request): bool
    {
        $expected = (string) config('hupm.health.token', '');

        if ($expected === '') {
            return false;
        }

        $presented = (string) ($request->header('X-Health-Token') ?? $request->query('token') ?? '');

        // Constant time: a timing oracle on a token is still a timing oracle.
        return $presented !== '' && hash_equals($expected, $presented);
    }
}
