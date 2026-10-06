<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Where a content button actually sends this visitor.  [WP-54]
 *
 * The public site's buttons are content: an editor picks a route name from
 * `SectionCatalogue::routeOptions()` and writes a label. One of those options
 * is `login`, which is right for the visitor it was written for and wrong for
 * somebody already signed in — "Resident login" offering to log in a resident
 * who is logged in.
 *
 * Resolved here rather than in each partial so the hero and the call-to-action
 * band cannot drift apart, and so the rule is somewhere a test can reach
 * without rendering a page.
 *
 * Only `login` is rewritten. Every other route means what it says to everybody,
 * and a signed-in visitor still wants the contact page to be the contact page.
 */
class PublicAction
{
    /** @return array{href: string, label: string} */
    public static function resolve(string $routeName, string $label): array
    {
        $user = Auth::user();

        if ($routeName === 'login' && $user !== null) {
            return [
                // By role: /admin, /portal, /work, /agency. Sending everyone to
                // /portal would 403 the agency and the contractor at the door.
                'href' => $user->homeRoute(),
                'label' => 'Go to your account',
            ];
        }

        return ['href' => route($routeName), 'label' => $label];
    }
}
