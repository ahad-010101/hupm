<?php

namespace App\Domain\Settings;

/**
 * What each setting is, and which values the code will actually accept.
 *
 * The `settings` table carries a key, a value, a type and a description. What
 * it cannot carry is the set of values the application recognises, because that
 * set lives in `match` statements and registries — and a list duplicated in the
 * database would drift from the one the code enforces.
 *
 * That matters more than it sounds. `AllocationOrderRegistry` throws on an
 * unrecognised key rather than falling back, deliberately: a typo must not
 * quietly change how money is applied. Which means a settings screen offering
 * free text would let somebody set `payment.allocation_order` to anything and
 * discover it at the next payment. So the screen offers a list, and the request
 * validates against the same list.
 *
 * A key not described here cannot be edited at all. Settings are a small, known
 * set; anything else arriving from a form is a bug or an attack, not a new
 * preference.
 */
class SettingsCatalogue
{
    /**
     * @return array<string, array{
     *     group: string,
     *     label: string,
     *     help: string,
     *     input: string,
     *     options?: array<string, string>,
     *     min?: int,
     *     max?: int,
     *     warning?: string,
     * }>
     */
    public function all(): array
    {
        return [
            /*
             | Company — shown to residents and on the public site.
             */
            'company.name' => [
                'group' => 'Company',
                'label' => 'Company name',
                'help' => 'Appears throughout the resident portal, on letters and on the public site.',
                'input' => 'text',
            ],
            'company.phone' => [
                'group' => 'Company',
                'label' => 'Office telephone',
                'help' => 'The number residents are given when they are asked to contact the office.',
                'input' => 'text',
            ],
            'company.emergency_phone' => [
                'group' => 'Company',
                'label' => 'Out-of-hours emergency number',
                'help' => 'Printed on the emergency maintenance page and in the repair form. '
                    .'Must be set before go-live — a blank one is a resident with a burst pipe and nobody to ring.',
                'input' => 'text',
            ],
            'company.address' => [
                'group' => 'Company',
                'label' => 'Mailing address',
                'help' => 'Used on generated documents and the public contact page.',
                'input' => 'text',
            ],
            'company.timezone' => [
                'group' => 'Company',
                'label' => 'Business timezone',
                'help' => 'Every business date resolves here — rent due days, grace periods, '
                    .'the day an account falls into review. Storage stays UTC.',
                'input' => 'select',
                'options' => [
                    'America/New_York' => 'Eastern (America/New_York)',
                    'America/Chicago' => 'Central (America/Chicago)',
                    'America/Denver' => 'Mountain (America/Denver)',
                    'America/Los_Angeles' => 'Pacific (America/Los_Angeles)',
                ],
                'warning' => 'Changing this moves every due date and grace expiry in the system. '
                    .'It is not a display preference.',
            ],

            /*
             | How money is applied.
             */
            'payment.allocation_order' => [
                'group' => 'Money',
                'label' => 'Payment application order',
                'help' => 'Which outstanding charge a payment meets first when it does not cover everything.',
                'input' => 'select',
                'options' => [
                    'oldest_charge_first' => 'Oldest charge first',
                    'rent_before_fees' => 'Rent before fees',
                    'newest_charge_first' => 'Newest charge first',
                ],
                'warning' => 'Applies to payments recorded from now on. Payments already applied are not re-run.',
            ],
            'payments.overpayment_behaviour' => [
                'group' => 'Money',
                'label' => 'When a resident overpays',
                'help' => 'Whether the extra is held against future rent or flagged for a refund.',
                'input' => 'select',
                'options' => [
                    'credit_forward' => 'Hold it as a credit against future rent',
                    'refund' => 'Flag it for a refund — somebody has to send it back',
                ],
            ],
            'charges.proration_method' => [
                'group' => 'Money',
                'label' => 'Part-month rent',
                'help' => 'How rent is worked out when a tenancy starts or ends mid-month.',
                'input' => 'select',
                'options' => [
                    'daily' => 'By the day',
                    'none' => 'Charge the full month regardless',
                ],
            ],
            'charges.recert_max_backdate_days' => [
                'group' => 'Money',
                'label' => 'Recertification backdating limit',
                'help' => 'How many days back a Section 8 recertification may be applied. '
                    .'Zero means a manual adjustment is required instead.',
                'input' => 'number',
                'min' => 0,
                'max' => 365,
            ],
            'deposits.tracked_in_ledger' => [
                'group' => 'Money',
                'label' => 'Security deposits on the ledger',
                'help' => 'Whether a deposit is a liability on the resident ledger or just a figure on the lease.',
                'input' => 'bool',
                'warning' => 'Turning this on changes what a resident balance means. Do not change it '
                    .'once real balances are loaded without agreeing the treatment first.',
            ],

            /*
             | Fees. The one setting with legal exposure attached.
             */
            'fees.automation_enabled' => [
                'group' => 'Late fees',
                'label' => 'Charge late fees automatically',
                'help' => 'Ships switched off. The nightly job records that it was skipped, so '
                    .'"switched off" and "the job is broken" never look the same.',
                'input' => 'bool',
                'warning' => 'Do not switch on before an attorney has reviewed the fee terms. '
                    .'A daily fee with no maximum is currently possible: six months at $5 a day is '
                    .'$900 on top of the rent, which a Georgia magistrate may not find reasonable.',
            ],
            'fees.returned_fee_automatic' => [
                'group' => 'Late fees',
                'label' => 'Post the returned-payment fee automatically',
                'help' => 'When a bank payment bounces, whether the fee posts by itself or waits for an admin.',
                'input' => 'bool',
            ],

            /*
             | Chasing late rent.
             */
            'delinquency.trigger_day' => [
                'group' => 'Late rent',
                'label' => 'Days past due before Management Review',
                'help' => 'Counted from the contractual due date, portfolio-wide. An account with a '
                    .'balance is flagged for review on this day and loses online payment.',
                'input' => 'number',
                'min' => 0,
                'max' => 28,
                'warning' => 'This clock is separate from the grace period on each lease. A lease '
                    .'granting more grace than this loses online payment while still inside its own '
                    .'grace and before any late fee. The review queue names any lease affected.',
            ],

            /*
             | Payment methods.
             */
            'payments.cards_enabled' => [
                'group' => 'Payment methods',
                'label' => 'Accept card payments',
                'help' => 'Out of scope for this version. Card payments are not reconciled, so '
                    .'offering the field would take money the system cannot account for.',
                'input' => 'bool',
                'warning' => 'Leave off. Nothing downstream handles a card payment yet.',
            ],

            /*
             | Who can log in.
             */
            'roles.owner_enabled' => [
                'group' => 'Access',
                'label' => 'Property owners can log in',
                'help' => 'Whether owners get their own read-only view of their properties.',
                'input' => 'bool',
                'warning' => 'The owner screens are not built. Switching this on grants a role with '
                    .'nowhere to go — and what an owner may see is still an open question.',
            ],

            /*
             | Housekeeping.
             */
            'reconciliation.stale_hours' => [
                'group' => 'Operations',
                'label' => 'Warn when reconciliation is this many hours old',
                'help' => 'A reconciliation that has stopped is invisible by nature — nothing errors, '
                    .'balances just drift. This is how long before the warning appears.',
                'input' => 'number',
                'min' => 1,
                'max' => 168,
                'warning' => 'Raising this makes the system slower to tell you it has stopped talking to the bank.',
            ],
            'retention.notices_days' => [
                'group' => 'Retention',
                'label' => 'Keep notices for (days)',
                'help' => 'Zero keeps them indefinitely.',
                'input' => 'number',
                'min' => 0,
                'max' => 3650,
            ],
            'retention.tickets_days' => [
                'group' => 'Retention',
                'label' => 'Keep repair tickets for (days)',
                'help' => 'Zero keeps them indefinitely.',
                'input' => 'number',
                'min' => 0,
                'max' => 3650,
            ],
            'retention.signed_documents_days' => [
                'group' => 'Retention',
                'label' => 'Keep signed documents for (days)',
                'help' => 'Zero keeps them indefinitely. A signed agreement is evidence; think hard before setting this.',
                'input' => 'number',
                'min' => 0,
                'max' => 3650,
            ],
        ];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @return array{group: string, label: string, help: string, input: string, options?: array<string, string>, min?: int, max?: int, warning?: string}|null */
    public function describe(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Is this a value the application will accept for this key?
     *
     * The same check the screen renders from, so a hand-crafted form post
     * cannot set something the dropdown would not have offered.
     */
    public function accepts(string $key, string $value): bool
    {
        $spec = $this->describe($key);

        if ($spec === null) {
            return false;
        }

        return match ($spec['input']) {
            'select' => array_key_exists($value, $spec['options'] ?? []),
            'bool' => in_array($value, ['true', 'false'], true),
            'number' => ctype_digit($value)
                && (int) $value >= ($spec['min'] ?? 0)
                && (int) $value <= ($spec['max'] ?? PHP_INT_MAX),
            // Free text still has a ceiling; the column is not unbounded.
            default => mb_strlen($value) <= 255,
        };
    }
}
