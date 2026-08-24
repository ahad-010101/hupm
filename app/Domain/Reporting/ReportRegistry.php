<?php

namespace App\Domain\Reporting;

use InvalidArgumentException;

/**
 * The five reports, and how to build one by name.  [FR-ADM-02, API-ADM-32…35]
 *
 * One place that knows the set, so the screen, the router and the export all
 * agree on what exists. A report reachable on screen but not exportable — or
 * exportable but missing from the picker — is the sort of gap that survives
 * because nothing lists them together.
 *
 * An unknown key throws rather than falling back to a default. Silently
 * returning the rent roll when somebody asked for arrears puts the wrong
 * numbers under the right heading, which is worse than an error.
 */
class ReportRegistry
{
    public function __construct(
        private readonly RentRollReport $rentRoll,
        private readonly CollectionsReport $collections,
        private readonly AgedArrearsReport $agedArrears,
        private readonly SectionEightReport $sectionEight,
        private readonly PaymentHistoryReport $payments,
    ) {}

    /** @return list<array{key: string, label: string, blurb: string}> */
    public function available(): array
    {
        return [
            ['key' => 'rent-roll', 'label' => 'Rent roll', 'blurb' => 'What was charged for a month, and how much of it came in.'],
            ['key' => 'collections', 'label' => 'Collections', 'blurb' => 'Charged against collected, twelve months back.'],
            ['key' => 'aged-arrears', 'label' => 'Aged arrears', 'blurb' => 'How old the money owed is, in 30-day bands.'],
            ['key' => 'section-8', 'label' => 'Section 8 split', 'blurb' => 'Who owes which share of the rent on every active lease.'],
            ['key' => 'payments', 'label' => 'Payment history', 'blurb' => 'Every payment attempt, including the ones that came back.'],
        ];
    }

    public function has(string $key): bool
    {
        return in_array($key, array_column($this->available(), 'key'), true);
    }

    /** @param array<string, string|null> $options */
    public function build(string $key, array $options = []): Report
    {
        return match ($key) {
            'rent-roll' => $this->rentRoll->build($options['period'] ?? null),
            'collections' => $this->collections->build(),
            'aged-arrears' => $this->agedArrears->build(),
            'section-8' => $this->sectionEight->build(),
            'payments' => $this->payments->build($options['status'] ?? null),
            default => throw new InvalidArgumentException("There is no report called '{$key}'."),
        };
    }
}
