<?php

namespace App\Domain\Arrangements;

use App\Domain\Documents\DocumentVault;
use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Signatures\SignatureService;
use App\Models\Document;
use App\Models\Lease;
use App\Models\PaymentArrangement;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Payment arrangements and their written agreement.  [FR-ARR-02, BR-19, WP-25]
 *
 * **Every approved partial payment produces a written agreement** — that is
 * BR-19 and it is not optional. The agreement carries all nine required
 * elements, is filed in the resident's vault, and is routed for signature.
 *
 * The money on an arrangement is a **snapshot**, taken when the parties agreed
 * it. Nothing here recomputes `remaining_balance` afterwards: it is a term of a
 * signed document, and a figure that drifted with the ledger would quietly
 * rewrite what somebody put their name to.
 */
class ArrangementService
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly DocumentVault $vault,
        private readonly SignatureService $signatures,
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Draft an arrangement against what the resident owes today.
     *
     * @param  list<array{due_on: string, amount: string}>  $schedule
     */
    public function draft(
        Lease $lease,
        Money $amountAccepted,
        array $schedule,
        bool $lateFeesContinue,
        string $defaultTerms,
        User $actor,
    ): PaymentArrangement {
        $owed = $this->balances->tenantBalance($lease->tenant_id);

        if (! $owed->isPositive()) {
            throw new InvalidArgumentException('There is nothing outstanding to arrange.');
        }

        if ($amountAccepted->greaterThan($owed)) {
            throw new InvalidArgumentException(
                "That is more than the {$owed->format()} outstanding."
            );
        }

        $remaining = $owed->minus($amountAccepted);
        $normalised = $this->normaliseSchedule($schedule);

        if ($normalised !== []) {
            $scheduled = Money::sum(array_map(fn ($i) => Money::fromString($i['amount']), $normalised));

            if (! $scheduled->equals($remaining)) {
                // An agreement whose instalments do not add up to the balance
                // is one both parties will read differently later.
                throw new InvalidArgumentException(sprintf(
                    'The instalments come to %s but %s remains outstanding.',
                    $scheduled->format(),
                    $remaining->format(),
                ));
            }
        }

        if (trim($defaultTerms) === '') {
            throw new InvalidArgumentException('The agreement has to say what happens on a breach.');
        }

        $arrangement = new PaymentArrangement;
        $arrangement->forceFill([
            'lease_id' => $lease->id,
            // Snapshots. See the class comment.
            'total_owed' => $owed->toDecimalString(),
            'amount_accepted' => $amountAccepted->toDecimalString(),
            'remaining_balance' => $remaining->toDecimalString(),
            'schedule_json' => $normalised,
            'late_fees_continue' => $lateFeesContinue,
            'default_terms' => $defaultTerms,
            'status' => PaymentArrangement::STATUS_DRAFT,
            'approved_by_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->audit->record('arrangement.drafted', $arrangement, [
            'lease_id' => $lease->id,
            'total_owed' => $owed->toDecimalString(),
            'amount_accepted' => $amountAccepted->toDecimalString(),
            'instalments' => count($normalised),
        ]);

        return $arrangement;
    }

    /**
     * Approve it: write the agreement, file it, route it for signature.
     * [API-ADM-20, AC-ARR-04, AC-ARR-05]
     */
    public function approve(PaymentArrangement $arrangement, User $actor): PaymentArrangement
    {
        if ($arrangement->status !== PaymentArrangement::STATUS_DRAFT) {
            throw new InvalidArgumentException('That arrangement has already been approved.');
        }

        $lease = Lease::with(['tenant', 'unit.property'])->findOrFail($arrangement->lease_id);
        $tenant = $lease->tenant;

        if (! $tenant) {
            throw new InvalidArgumentException('That lease has no resident on it.');
        }

        $document = DB::transaction(function () use ($arrangement, $lease, $tenant, $actor) {
            $document = $this->fileAgreement($arrangement, $lease, $tenant, $actor);

            $arrangement->forceFill([
                'document_id' => $document->id,
                'approved_by_user_id' => $actor->id,
                'status' => PaymentArrangement::STATUS_PENDING_SIGNATURE,
                'updated_at' => now(),
            ])->save();

            return $document;
        });

        // Outside the transaction: creating a signature request sends an email,
        // and an email sent from inside a transaction that later rolls back is
        // a message about something that did not happen.
        $signer = $tenant->users()->where('role', 'tenant')->first();

        if ($signer) {
            $this->signatures->createRequest($document, $tenant, $signer, $actor);
        }

        $this->audit->record('arrangement.approved', $arrangement, [
            'document_id' => $document->id,
            // A resident with no portal login gets a paper copy; the agreement
            // still exists and is still filed (Q-4).
            'signature_requested' => $signer !== null,
        ]);

        return $arrangement->refresh();
    }

    /**
     * The agreement becomes live once it is signed.
     *
     * Called by the signature flow. An unsigned arrangement is a proposal, and
     * `PartialPaymentPolicy` only recognises a live one — so a resident cannot
     * pay under terms nobody has agreed to yet.
     */
    public function activate(PaymentArrangement $arrangement): void
    {
        if ($arrangement->status !== PaymentArrangement::STATUS_PENDING_SIGNATURE) {
            return;
        }

        $arrangement->forceFill([
            'status' => PaymentArrangement::STATUS_ACTIVE,
            'updated_at' => now(),
        ])->save();

        $this->audit->record('arrangement.activated', $arrangement, []);
    }

    /** A missed instalment. Recorded by a person, never inferred. */
    public function markDefaulted(PaymentArrangement $arrangement, string $reason, User $actor): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Recording a default requires a reason.');
        }

        $arrangement->forceFill([
            'status' => PaymentArrangement::STATUS_DEFAULTED,
            'updated_at' => now(),
        ])->save();

        $this->audit->record('arrangement.defaulted', $arrangement, [
            'reason' => $reason,
            'by' => $actor->id,
        ]);
    }

    /**
     * Render the agreement and put it in the vault.
     *
     * Written through DocumentVault rather than straight to disk, so it gets
     * the same UUID path, SHA-256 and audit row as anything else a resident
     * can download — and so the signature flow can hash it (BR-26).
     */
    private function fileAgreement(
        PaymentArrangement $arrangement,
        Lease $lease,
        Tenant $tenant,
        User $actor,
    ): Document {
        $agreedOn = $this->calendar->today();

        $pdf = Pdf::loadView('pdf.arrangement', [
            'arrangement' => $arrangement,
            'tenant' => $tenant,
            'address' => $this->addressFor($lease),
            'agreedOn' => $agreedOn,
            'approver' => $actor->name,
            'schedule' => $this->labelledSchedule($arrangement),
            'company' => [
                'name' => $this->settings->string('company.name', config('app.name')),
                'phone' => $this->settings->string('company.phone'),
                'address' => $this->settings->string('company.address'),
            ],
        ])->output();

        // The vault's store() takes an UploadedFile; this comes from dompdf, so
        // it goes through a temp file to reach the same code path rather than a
        // parallel one that could drift from it.
        $temp = tempnam(sys_get_temp_dir(), 'arr').'.pdf';
        file_put_contents($temp, $pdf);

        $upload = new UploadedFile(
            $temp,
            'payment-arrangement-'.$agreedOn->toDateString().'.pdf',
            'application/pdf',
            null,
            true,
        );

        return $this->vault->store($tenant, $upload, [
            'category' => 'payment_agreement',
            'title' => 'Payment arrangement — '.$agreedOn->format('j F Y'),
            'lease_id' => $lease->id,
            'visible_to_tenant' => true,
        ], $actor);
    }

    /**
     * Instalments, formatted for the document.
     *
     * @return list<array{due_on_label: string, amount_label: string}>
     */
    private function labelledSchedule(PaymentArrangement $arrangement): array
    {
        return array_map(fn (array $instalment) => [
            // Long form: this is read by a resident, and by a court (UI §8).
            'due_on_label' => CarbonImmutable::parse($instalment['due_on'])->format('j F Y'),
            'amount_label' => Money::fromString($instalment['amount'])->format(),
        ], $arrangement->schedule_json ?? []);
    }

    /**
     * @param  list<array{due_on: string, amount: string}>  $schedule
     * @return list<array{due_on: string, amount: string}>
     */
    private function normaliseSchedule(array $schedule): array
    {
        $normalised = [];

        foreach ($schedule as $instalment) {
            $amount = Money::fromString((string) ($instalment['amount'] ?? '0'));

            if (! $amount->isPositive()) {
                continue;
            }

            $normalised[] = [
                'due_on' => CarbonImmutable::parse($instalment['due_on'])->toDateString(),
                'amount' => $amount->toDecimalString(),
            ];
        }

        usort($normalised, fn ($a, $b) => strcmp($a['due_on'], $b['due_on']));

        return $normalised;
    }

    private function addressFor(Lease $lease): string
    {
        $unit = $lease->unit;
        $property = $unit?->property;

        if (! $property) {
            return 'the property';
        }

        return trim(sprintf(
            '%s, unit %s, %s, %s %s',
            $property->street_address,
            $unit->unit_number,
            $property->city,
            $property->state,
            $property->postal_code,
        ), ' ,');
    }
}
