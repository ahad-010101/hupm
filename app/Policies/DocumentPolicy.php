<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Who may touch a document.  [FR-AUTH-05, AC-AUTH-09, AC-AUTH-10, §5.3]
 *
 * Ownership is resolved from `$user->tenant_id` and compared to the record.
 * **No route parameter is trusted to establish ownership** (FR-AUTH-05) — a
 * tenant id in a URL is an assertion by the client, not a fact.
 *
 * The controller converts a denial into 404, never 403 (I-9). That is the
 * difference between "you may not see this" and "this is not here", and only
 * the second one avoids telling tenant A that tenant B is a customer.
 *
 * An owner gets 403 on every document route (AC-AUTH-10) — the role legitimately
 * exists but has no business with resident paperwork.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTenant();
    }

    public function view(User $user, Document $document): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isTenant()) {
            return $user->tenant_id !== null
                && $user->tenant_id === $document->tenant_id
                // A document can be withheld from the portal while still
                // existing for admin — an internal note on a lease, say.
                && (bool) $document->visible_to_tenant;
        }

        // Owner and anything else.
        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Document $document): bool
    {
        return $user->isAdmin() && ! $document->is_signed;
    }

    /** Nobody. Documents are superseded by a new version, never deleted. */
    public function delete(User $user, Document $document): bool
    {
        return false;
    }

    /** Only the tenant the document belongs to may sign it (§5.3). */
    public function sign(User $user, Document $document): bool
    {
        return $user->isTenant()
            && $user->tenant_id !== null
            && $user->tenant_id === $document->tenant_id;
    }
}
