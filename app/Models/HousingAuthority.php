<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Section 8 agency.  [FR-REG-01]
 *
 * `remittance_type` is the visible face of **[GATE Q-2]**, which is still
 * unanswered and one of the five blocking questions. If an authority turns out
 * to remit a lump sum rather than per tenant, WP-12 gains an allocation screen
 * — someone has to decide which tenants a single cheque covers, and the system
 * cannot guess (risk R-9).
 */
class HousingAuthority extends Model
{
    use HasFactory;

    public const REMITTANCE_PER_TENANT = 'per_tenant';

    public const REMITTANCE_LUMP_SUM = 'lump_sum';

    protected $fillable = [
        'name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'remittance_type',
    ];

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function isDeletable(): bool
    {
        return ! $this->leases()->exists();
    }
}
