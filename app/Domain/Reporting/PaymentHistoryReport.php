<?php

namespace App\Domain\Reporting;

use App\Models\Payment;
use App\Support\BusinessCalendar;
use App\Support\Money;

/**
 * Every payment attempt, in the order they were made.  [FR-ADM-02]
 *
 * Attempts, not just successes. A resident asking "I paid on the 3rd, where did
 * it go?" is answered by the row that says the payment was returned on the 9th,
 * not by its absence from a list of receipts.
 *
 * Admin wording throughout — `pending`, not the portal's `processing`. The
 * softer phrasing exists so a resident is never told their bank payment failed
 * (UI §8); using it here would hide from the office the state it has to chase.
 */
class PaymentHistoryReport
{
    private const LIMIT = 500;

    public function __construct(private readonly BusinessCalendar $calendar) {}

    public function build(?string $status = null): Report
    {
        $payments = Payment::query()
            ->with(['tenant:id,first_name,last_name'])
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $rows = [];
        $cleared = Money::zero();

        foreach ($payments as $payment) {
            if ($payment->status === Payment::STATUS_SETTLED) {
                $cleared = $cleared->plus($payment->amount);
            }

            $rows[] = [
                'received_on' => $payment->submitted_at?->format('j M Y') ?? '—',
                'tenant' => $payment->tenant?->fullName() ?? 'Unidentified',
                'payer' => $payment->payer === 'housing_authority' ? 'Authority' : 'Resident',
                'method' => $payment->method,
                'reference' => $payment->reference ?: '—',
                'amount' => (string) $payment->amount,
                'status' => $payment->status,
                'detail' => $this->detail($payment),
            ];
        }

        $notes = [
            'Only settled payments count towards the total. A pending payment has not arrived '
                .'and a returned one has been taken back.',
        ];

        if ($payments->count() === self::LIMIT) {
            // Never truncate silently. A report that quietly stops at 500 rows
            // reads as a complete answer.
            $notes[] = 'Showing the most recent '.self::LIMIT.' payments. There are older ones '
                .'than this — narrow by status to see them.';
        }

        return new Report(
            key: 'payments',
            title: 'Payment history',
            subtitle: $status
                ? ucfirst($status).' payments'
                : 'All payments, most recent first',
            columns: [
                ['key' => 'received_on', 'label' => 'Received'],
                ['key' => 'tenant', 'label' => 'Resident'],
                ['key' => 'payer', 'label' => 'Paid by'],
                ['key' => 'method', 'label' => 'Method'],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'amount', 'label' => 'Amount', 'money' => true],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'detail', 'label' => 'Detail'],
            ],
            rows: $rows,
            totals: [
                'received_on' => 'Total settled',
                'tenant' => count($rows).' payments',
                'payer' => '',
                'method' => '',
                'reference' => '',
                'amount' => (string) $cleared,
                'status' => '',
                'detail' => '',
            ],
            notes: $notes,
        );
    }

    private function detail(Payment $payment): string
    {
        if ($payment->return_code) {
            return trim($payment->return_code.' '.$payment->return_description);
        }

        return (string) ($payment->return_description ?: $payment->gateway_transaction_id ?: '—');
    }
}
