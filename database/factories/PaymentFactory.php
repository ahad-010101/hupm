<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'payer' => 'tenant',
            'amount' => '100.00',
            'method' => 'cheque',
            'gateway' => 'manual',
            'idempotency_key' => (string) Str::uuid(),
            'status' => Payment::STATUS_PENDING,
            'submitted_at' => now(),
        ];
    }

    /** Settled: the only state in which a payment may be allocated (I-6). */
    public function settled(): static
    {
        return $this->state(fn () => [
            'status' => Payment::STATUS_SETTLED,
            'settled_at' => now(),
        ]);
    }

    public function forHousingAuthority(): static
    {
        return $this->state(fn () => [
            'payer' => 'housing_authority',
            'method' => 'ha_remittance',
        ]);
    }
}
