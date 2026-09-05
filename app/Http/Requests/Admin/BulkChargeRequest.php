<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;
use App\Models\ChargeType;
use App\Models\Lease;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Charging many leases at once.  [WP-41, FR-CHG-01]
 *
 * A charge type is required even for a one-off. Charging many residents the
 * same thing means that thing has a name, and naming it once is the entire
 * point of the feature. A genuine single-resident one-off has its own route
 * already: Ledger → Post adjustment.
 */
class BulkChargeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-portfolio');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'charge_type_id' => ['required', 'exists:charge_types,id'],

            // Both overridable: the type is a starting point, not a rule. This
            // month's water bill is not last month's.
            'description' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],

            'scope' => ['required', 'in:all,property,selected'],
            'property_id' => ['required_if:scope,property', 'nullable', 'exists:properties,id'],
            'lease_ids' => ['required_if:scope,selected', 'array', 'min:1'],
            'lease_ids.*' => ['integer', 'exists:leases,id'],

            'timing' => ['required', 'in:once,monthly'],

            // The screen shows a total before it posts, and the browser sends
            // back what it showed. If the two disagree the data moved under the
            // admin — a lease ended, somebody else charged the same people —
            // and they should see the new figure rather than have their click
            // apply to a different set than the one they read.
            'confirmed_lease_count' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $leases = $this->targetLeases();

            if ($leases->isEmpty()) {
                $validator->errors()->add('scope', 'No active leases match that selection.');

                return;
            }

            if ($leases->count() !== (int) $this->integer('confirmed_lease_count')) {
                $validator->errors()->add('scope', sprintf(
                    'This now affects %d residents rather than %d. Check the list and confirm again.',
                    $leases->count(),
                    $this->integer('confirmed_lease_count'),
                ));
            }
        });
    }

    /**
     * The leases this posting will touch.
     *
     * **Active leases only, always.** Charging an ended tenancy creates a debt
     * on somebody who has moved out and a balance nobody will collect. The
     * scope chooses which active leases, never whether to include dead ones.
     *
     * @return Collection<int, Lease>
     */
    public function targetLeases(): Collection
    {
        return Lease::query()
            ->where('status', Lease::STATUS_ACTIVE)
            ->when(
                $this->input('scope') === 'property',
                fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('property_id', $this->integer('property_id'))),
            )
            ->when(
                $this->input('scope') === 'selected',
                fn ($q) => $q->whereIn('id', $this->input('lease_ids', [])),
            )
            ->with('tenant:id,first_name,last_name')
            ->get();
    }

    public function chargeType(): ChargeType
    {
        return ChargeType::findOrFail($this->integer('charge_type_id'));
    }

    /**
     * The amount, as Money.
     *
     * `BaseFormRequest::money()` is protected, so the controller cannot reach
     * it directly. Naming this one for the field rather than shadowing the
     * helper keeps the parent's signature intact.
     */
    public function amount(): Money
    {
        return $this->money('amount');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'lease_ids.required_if' => 'Choose at least one resident.',
            'lease_ids.min' => 'Choose at least one resident.',
        ];
    }
}
