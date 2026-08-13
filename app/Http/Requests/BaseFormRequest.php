<?php

namespace App\Http\Requests;

use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every write action in the system.
 *
 * The layering rule is Controller → FormRequest → Domain service → Model, and
 * a controller never reads `$request->all()` into a model (invariant I-11).
 * This class exists to make the correct path the easy one:
 *
 *   - `authorize()` defaults to **false**. Every subclass must state its own
 *     rule, so forgetting authorisation produces a 403 in development rather
 *     than an open endpoint in production.
 *   - `money()` returns validated input as App\Support\Money, so a form value
 *     never becomes a float on the way to a domain service (I-10).
 *   - `businessDate()` resolves a submitted date in the company timezone rather
 *     than UTC, so "today" means what the admin in Georgia meant (D-07).
 */
abstract class BaseFormRequest extends FormRequest
{
    /** Fail closed. Subclasses must override with a real rule. */
    public function authorize(): bool
    {
        return false;
    }

    /** A validated monetary field as Money. */
    protected function money(string $key, ?Money $default = null): Money
    {
        $value = $this->validated()[$key] ?? null;

        if ($value === null || $value === '') {
            return $default ?? Money::zero();
        }

        return Money::fromString((string) $value);
    }

    /** A validated date field, resolved in the company timezone. */
    protected function businessDate(string $key): ?CarbonImmutable
    {
        $value = $this->validated()[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, app(BusinessCalendar::class)->timezone())->startOfDay();
    }
}
