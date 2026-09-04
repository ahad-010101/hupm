<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The decision register from 06-Implementation-Plan.md §6, as data.
 *
 * Twenty client questions are unanswered and five block development. Rather
 * than hard-code a guess, every gated decision ships as a row here with a
 * documented default, so a late answer is a configuration change instead of a
 * code change.
 *
 * Rows with is_gated = true must be confirmed by the client before go-live:
 * WP-35 cannot complete while any gated row has a NULL confirmed_at. Seeding a
 * default is not the same as having an answer, and this column is what keeps
 * those two things apart.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // [key, value, type, gated, description]
        $settings = [
            // ── Blocking client questions ───────────────────────────────────
            ['payment.allocation_order', 'oldest_charge_first', 'string', true,
                'Q-1 BLOCKING. Order in which a payment is applied to outstanding charges. Georgia dispossessory implications — confirm with counsel.'],
            ['charges.recert_max_backdate_days', '0', 'int', true,
                'Q-3 BLOCKING. How far back a Section 8 recertification may be applied. 0 = manual adjustment only.'],

            // ── Fees and delinquency ────────────────────────────────────────
            ['delinquency.trigger_day', '5', 'int', true,
                'Q-6. Day of month past due on which a lease enters Management Review.'],
            ['fees.returned_fee_automatic', 'true', 'bool', true,
                'Q-9. Whether the returned-payment fee posts automatically or awaits admin action.'],
            ['fees.automation_enabled', 'false', 'bool', true,
                'Ships disabled pending attorney review of the fee language (WP-23). Late fees are calculated but not posted while false.'],

            // ── Payments ────────────────────────────────────────────────────
            ['payments.cards_enabled', 'false', 'bool', true,
                'Q-7 (closed 2026-09-04, yes). Cards ship switched off; turning this on offers them alongside ACH (WP-39).'],
            ['payments.card_convenience_fee', '0.00', 'string', true,
                'Q-7a. Flat fee added to a card payment, posted as its own ledger line. 0.00 means the landlord absorbs the processing cost.'],
            ['payments.overpayment_behaviour', 'credit_forward', 'string', true,
                'Q-8. What happens to an overpayment remainder: credit_forward or refund.'],

            // ── Charges ─────────────────────────────────────────────────────
            ['charges.proration_method', 'daily', 'string', true,
                'Q-10. Proration convention for partial months.'],

            // ── Roles and data model ────────────────────────────────────────
            ['roles.owner_enabled', 'false', 'bool', true,
                'Q-11. Whether the owner role ships, and its scope (WP-28).'],
            ['deposits.tracked_in_ledger', 'false', 'bool', true,
                'Q-12 (closed 2026-09-04, yes). Ships off. When on, a new lease charges its security deposit to the tenant (WP-40). Never retrospective.'],

            // ── Retention ───────────────────────────────────────────────────
            ['retention.notices_days', '0', 'int', true, 'Q-16. 0 = retain indefinitely.'],
            ['retention.tickets_days', '0', 'int', true, 'Q-16. 0 = retain indefinitely.'],
            ['retention.signed_documents_days', '0', 'int', true, 'Q-16. 0 = retain indefinitely.'],

            // ── Company details (not gated, but must be set before go-live) ──
            ['company.timezone', 'America/New_York', 'string', false,
                'D-07. Every business date resolves here via BusinessCalendar. Storage stays UTC.'],
            ['company.name', 'Heads Up Enterprises', 'string', false, 'Displayed throughout the tenant portal and public site.'],
            ['company.phone', '', 'string', false, 'Public contact number.'],
            ['company.address', '', 'string', false, 'Public mailing address.'],
            ['company.emergency_phone', '', 'string', false,
                'Shown on the Emergency Maintenance Instructions page. Must be set before go-live.'],
            ['company.email', '', 'string', false,
                'Where the public contact form delivers. While it is blank the form is withdrawn and '
                .'the telephone number offered instead, rather than accepting messages nobody receives.'],
            ['company.office_hours', '', 'string', false,
                'Shown on the public contact and about pages, so a caller knows whether anyone is there.'],

            // ── Operational thresholds ──────────────────────────────────────
            ['reconciliation.stale_hours', '36', 'int', false,
                'R-6. Hours without a successful reconciliation before the admin dashboard raises a banner.'],
        ];

        foreach ($settings as [$key, $value, $type, $gated, $description]) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => $value,
                    'type' => $type,
                    'description' => $description,
                    'is_gated' => $gated,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
