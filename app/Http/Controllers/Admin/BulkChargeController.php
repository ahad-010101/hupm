<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Charges\BulkChargeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkChargeRequest;
use App\Models\ChargeBatch;
use App\Models\ChargeType;
use App\Models\Lease;
use App\Models\LeaseChargeSchedule;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Charging many residents at once.  [WP-41, FR-CHG-01]
 */
class BulkChargeController extends Controller
{
    public function __construct(private readonly BulkChargeService $charges) {}

    /** The posting screen, plus what has been posted before. */
    public function index(): Response
    {
        return Inertia::render('Admin/Charges/Index', [
            'types' => ChargeType::active()->orderBy('name')->get()
                ->map(fn (ChargeType $type) => [
                    ...$type->only(['id', 'name', 'category', 'default_description', 'payer']),
                    'default_amount' => (string) $type->default_amount,
                ]),

            'properties' => Property::query()->orderBy('name')->get(['id', 'name']),

            // Active leases only. A charge against an ended tenancy is a debt on
            // somebody who has moved out.
            'leases' => Lease::query()
                ->where('status', Lease::STATUS_ACTIVE)
                ->with(['tenant:id,first_name,last_name', 'unit:id,unit_number,property_id', 'unit.property:id,name'])
                ->get()
                ->map(fn (Lease $lease) => [
                    'id' => $lease->id,
                    'tenant' => $lease->tenant?->fullName(),
                    'property' => $lease->unit?->property?->name,
                    'property_id' => $lease->unit?->property_id,
                    'unit' => $lease->unit?->unit_number,
                ])
                ->sortBy(['property', 'unit'])
                ->values(),

            'batches' => ChargeBatch::query()
                ->with('postedBy:id,name')
                ->latest('posted_at')
                ->limit(25)
                ->get()
                ->map(fn (ChargeBatch $batch) => [
                    ...$batch->only(['id', 'description', 'lease_count', 'reversed_reason']),
                    'amount' => (string) $batch->amount,
                    'total_amount' => (string) $batch->total_amount,
                    'posted_at' => $batch->posted_at?->toDateString(),
                    'posted_by' => $batch->postedBy?->name,
                    'is_reversed' => $batch->isReversed(),
                ]),

            'schedules' => LeaseChargeSchedule::query()
                ->whereNotNull('charge_type_id')
                ->where('active', true)
                ->selectRaw('charge_type_id, COUNT(*) as leases, SUM(amount) as monthly_total')
                ->groupBy('charge_type_id')
                ->with('chargeType:id,name')
                ->get()
                ->map(fn ($row) => [
                    'charge_type_id' => $row->charge_type_id,
                    'name' => $row->chargeType?->name,
                    'leases' => (int) $row->leases,
                    'monthly_total' => (string) $row->monthly_total,
                ]),
        ]);
    }

    public function store(BulkChargeRequest $request): RedirectResponse
    {
        $type = $request->chargeType();
        $leases = $request->targetLeases();

        if ($request->input('timing') === 'monthly') {
            $result = $this->charges->scheduleMonthly(
                $leases,
                $type,
                $type->category,
                $request->string('description')->value(),
                $request->amount(),
                $type->payer,
                $request->user(),
            );

            return back()->with('status', sprintf(
                '%s is now charged monthly to %d %s. It posts with the rent from next month.',
                $type->name,
                $leases->count(),
                $leases->count() === 1 ? 'resident' : 'residents',
            ).($result['updated'] > 0 ? " {$result['updated']} existing were updated." : ''));
        }

        $batch = $this->charges->postOnce(
            $leases,
            $type,
            $type->category,
            $request->string('description')->value(),
            $request->amount(),
            $type->payer,
            $request->user(),
        );

        return back()->with('status', sprintf(
            'Charged %d %s %s each, %s in total.',
            $batch->lease_count,
            $batch->lease_count === 1 ? 'resident' : 'residents',
            $batch->amount->format(),
            $batch->total_amount->format(),
        ));
    }

    /** FR-LED-04 / I-3: reversing entries, never deletes. */
    public function reverse(Request $request, ChargeBatch $batch): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'A reversal needs a reason. It becomes part of the permanent record.',
        ]);

        try {
            $reversed = $this->charges->reverseBatch($batch, $validated['reason'], $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%d %s reversed. The original entries stay on each ledger alongside the reversal.',
            $reversed,
            $reversed === 1 ? 'charge' : 'charges',
        ));
    }

    /** Stop a monthly charge. Not an undo — what is posted stays posted. */
    public function stopSchedule(Request $request, ChargeType $chargeType): RedirectResponse
    {
        $stopped = $this->charges->deactivateSchedules($chargeType, $request->user());

        return back()->with('status', sprintf(
            'Stopped %s for %d %s. Charges already posted are unaffected.',
            $chargeType->name,
            $stopped,
            $stopped === 1 ? 'resident' : 'residents',
        ));
    }
}
