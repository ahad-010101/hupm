<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repair request.  [FR-MNT-01…03, DB §A3]
 *
 * Writes go through MaintenanceService: the ticket number, the state machine
 * and the event trail have to move together, and a model that could be saved
 * directly would let one of the three drift.
 */
class MaintenanceRequest extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_tenant_confirmation';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /** FR-MNT-01. The twelve from the source document, in its order. */
    public const CATEGORIES = [
        'plumbing' => 'Plumbing',
        'electrical' => 'Electrical',
        'hvac' => 'Heating or air conditioning',
        'appliance' => 'Appliance',
        'roof_leak' => 'Roof or leak',
        'pest' => 'Pests',
        'locks_doors' => 'Locks or doors',
        'smoke_co_detector' => 'Smoke or carbon monoxide detector',
        'structural' => 'Structural',
        'lawn_exterior' => 'Lawn or outside',
        'section8_inspection_repair' => 'Section 8 inspection repair',
        'other' => 'Something else',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'date_began' => 'date',
            'scheduled_at' => 'datetime',
            'closed_at' => 'datetime',
            'permission_to_enter' => 'boolean',
            'pets_present' => 'boolean',
            'is_emergency' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MaintenanceEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MaintenanceAttachment::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Maintenance';
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_CLOSED, self::STATUS_CANCELLED], true);
    }

    /**
     * The admin queue's order.  [AC-MNT-04]
     *
     * Emergencies first, then oldest — because the thing that has been waiting
     * longest is the thing most likely to have been forgotten, and a queue
     * sorted newest-first buries it.
     */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_emergency')->orderBy('created_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_CLOSED, self::STATUS_CANCELLED]);
    }
}
