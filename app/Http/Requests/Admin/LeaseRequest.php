<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Models\Lease;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Lease create and update.  [FR-REG-02, AC-REG-03…06, FS §18.4]
 *
 * Carries every field in DB `leases`, including the FR-ARR-01 partial-payment
 * settings — which live on this form rather than a screen of their own
 * (GAP-1, DB §C1), because they are terms of the tenancy and belong beside the
 * other terms.
 */
class LeaseRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $lease = $this->route('lease');

        return [
            'unit_id' => ['required', 'exists:units,id'],
            'tenant_id' => ['required', 'exists:tenants,id'],
            'housing_authority_id' => ['nullable', 'exists:housing_authorities,id'],

            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],

            // Money arrives as a decimal string and is compared with Money, not
            // floats (I-10). `decimal:0,2` rejects a third decimal place rather
            // than letting MySQL round it away.
            'total_contract_rent' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'tenant_portion' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'ha_portion' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],

            // AC-REG-06: 1–28 only. The 29th, 30th and 31st do not exist in
            // every month, and "rent is due on the 31st" has no answer in
            // February. Capping the input removes the ambiguity rather than
            // resolving it later with a rule nobody agreed.
            'rent_due_day' => ['required', 'integer', 'between:1,28'],
            'grace_period_days' => ['required', 'integer', 'between:0,60'],

            'late_fee_flat' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'late_fee_daily' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'late_fee_max' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'returned_payment_fee' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'security_deposit' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],

            'is_subsidised' => ['required', 'boolean'],
            'hap_contract_number' => ['nullable', 'string', 'max:60'],
            'utility_responsibility' => ['nullable', 'string', 'max:255'],

            // FR-ARR-01 partial-payment policy (GAP-1).
            'partial_payment_policy' => ['required', Rule::in([
                'full_only', 'partial_allowed', 'before_due_only', 'under_arrangement_only',
            ])],
            'partial_minimum_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'partial_policy_expires_on' => ['nullable', 'date'],
            'partial_requires_approval' => ['required', 'boolean'],
            'ledger_review_required' => ['required', 'boolean'], // [GATE C1]

            'status' => ['required', Rule::in([
                Lease::STATUS_DRAFT, Lease::STATUS_ACTIVE, Lease::STATUS_ENDED,
            ])],

            // FS §18.4 optimistic locking. Present only when editing.
            'lock_version' => [$lease ? 'required' : 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertPortionsSum($validator);
            $this->assertSubsidyHasAgency($validator);
            $this->assertNoOtherActiveLease($validator);
            $this->assertNotStale($validator);
        });
    }

    /** AC-REG-03. */
    private function assertPortionsSum(Validator $validator): void
    {
        foreach (['total_contract_rent', 'tenant_portion', 'ha_portion'] as $field) {
            if ($validator->errors()->has($field) || $this->input($field) === null) {
                return; // a malformed number would produce a confusing second error
            }
        }

        $total = Money::fromString((string) $this->input('total_contract_rent'));
        $sum = Money::fromString((string) $this->input('tenant_portion'))
            ->plus(Money::fromString((string) $this->input('ha_portion')));

        if (! $sum->equals($total)) {
            // The exact wording the specification requires.
            $validator->errors()->add('tenant_portion', 'Portions must sum to total contract rent.');
        }
    }

    /** AC-REG-05. */
    private function assertSubsidyHasAgency(Validator $validator): void
    {
        if ($this->boolean('is_subsidised') && ! $this->input('housing_authority_id')) {
            $validator->errors()->add(
                'housing_authority_id',
                'A subsidised lease must name the Section 8 agency that pays its portion.',
            );
        }
    }

    /**
     * AC-REG-04, checked here for a usable message.
     *
     * The database is the real guarantee (D-03's generated column) — this check
     * races and the constraint does not. LeaseService catches the violation and
     * produces the same message, so a lost race reads the same as a caught one.
     */
    private function assertNoOtherActiveLease(Validator $validator): void
    {
        if ($this->input('status') !== Lease::STATUS_ACTIVE || ! $this->input('unit_id')) {
            return;
        }

        $lease = $this->route('lease');

        $clash = Lease::where('unit_id', $this->input('unit_id'))
            ->where('status', Lease::STATUS_ACTIVE)
            ->when($lease, fn ($q) => $q->whereKeyNot($lease->id))
            ->exists();

        if ($clash) {
            $validator->errors()->add(
                'unit_id',
                'That unit already has an active lease. End the existing lease first.',
            );
        }
    }

    /** FS §18.4: two admins editing one lease. */
    private function assertNotStale(Validator $validator): void
    {
        $lease = $this->route('lease');

        if (! $lease || ! $this->input('lock_version')) {
            return;
        }

        // The version the form was rendered against, compared with the row now.
        // A silent last-write-wins on a lease means one admin's fee
        // configuration quietly replaces another's, and neither is told.
        //
        // Millisecond precision (D-18): on a whole-second TIMESTAMP two saves
        // inside the same second are identical, so the check would pass exactly
        // when it is most needed.
        if ($lease->lockVersion() !== $this->input('lock_version')) {
            $validator->errors()->add(
                'lock_version',
                'Someone else changed this lease while you were editing it. Reload the page and reapply your changes.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        return $this->safe()->except(['lock_version']);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'unit_id' => 'unit',
            'tenant_id' => 'tenant',
            'housing_authority_id' => 'Section 8 agency',
            'total_contract_rent' => 'total contract rent',
            'tenant_portion' => 'tenant portion',
            'ha_portion' => 'housing authority portion',
            'rent_due_day' => 'rent due day',
        ];
    }
}
