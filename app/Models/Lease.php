<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenancy and its financial configuration.
 *
 * Minimal for now — WP-07 builds the full lease form, the
 * tenant_portion + ha_portion = total_contract_rent rule (AC-REG-03), and
 * termination. It exists here so the registry's RESTRICT checks can ask whether
 * a unit or authority is in use.
 *
 * Not fillable: a lease carries money and the D-03 active-unit constraint, so
 * every write goes through a domain service rather than mass assignment (I-11).
 */
class Lease extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'delinquency_since' => 'date',
            'partial_policy_expires_on' => 'date',
            'total_contract_rent' => MoneyCast::class,
            'tenant_portion' => MoneyCast::class,
            'ha_portion' => MoneyCast::class,
            'late_fee_flat' => MoneyCast::class,
            'late_fee_daily' => MoneyCast::class,
            'late_fee_max' => MoneyCast::class,
            'returned_payment_fee' => MoneyCast::class,
            'security_deposit' => MoneyCast::class,
            'partial_minimum_amount' => MoneyCast::class,
            'is_subsidised' => 'boolean',
            'partial_requires_approval' => 'boolean',
            'ledger_review_required' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function housingAuthority(): BelongsTo
    {
        return $this->belongsTo(HousingAuthority::class);
    }
}
