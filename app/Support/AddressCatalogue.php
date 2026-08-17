<?php

namespace App\Support;

use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepository;

/**
 * Country and subdivision data for address forms.  [D-19]
 *
 * Wraps commerceguys/addressing, which carries Google's libaddressinput data —
 * the same dataset Chrome autofill uses. That matters for the parts a
 * hand-rolled JSON gets wrong:
 *
 *   - the field is called "State" in the US, "Province" in Canada,
 *     "Prefecture" in Japan, and nothing at all in the UK;
 *   - some countries have a fixed subdivision list and some have none, so the
 *     control has to be a select in one case and a text box in the other;
 *   - postal code formats differ, and "five digits" is a US rule, not a
 *     universal one.
 *
 * Wrapped rather than used directly so the rest of the application depends on
 * our shape, not the library's, and so the lists can be cached — building the
 * country list parses a sizeable dataset and it never changes within a request.
 */
class AddressCatalogue
{
    /** Countries the portfolio realistically uses, floated to the top of the list. */
    private const PRIORITY = ['US', 'CA'];

    public function __construct(
        private readonly CountryRepository $countries = new CountryRepository,
        private readonly SubdivisionRepository $subdivisions = new SubdivisionRepository,
        private readonly AddressFormatRepository $formats = new AddressFormatRepository,
    ) {}

    /**
     * Every country, as {code, name}, with the ones we actually operate in first.
     *
     * @return list<array{code: string, name: string}>
     */
    public function countries(): array
    {
        return cache()->rememberForever('address.countries', function (): array {
            $all = $this->countries->getList('en');
            asort($all);

            $priority = [];
            foreach (self::PRIORITY as $code) {
                if (isset($all[$code])) {
                    $priority[] = ['code' => $code, 'name' => $all[$code]];
                    unset($all[$code]);
                }
            }

            $rest = array_map(
                fn (string $code, string $name) => ['code' => $code, 'name' => $name],
                array_keys($all),
                $all,
            );

            return [...$priority, ...$rest];
        });
    }

    /**
     * Subdivisions for a country. Empty when the country has none — the form
     * must then accept free text rather than showing an empty dropdown.
     *
     * @return list<array{code: string, name: string}>
     */
    public function subdivisions(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        return cache()->rememberForever("address.subdivisions.{$countryCode}", function () use ($countryCode): array {
            $list = $this->subdivisions->getList([$countryCode], 'en');
            asort($list);

            return array_map(
                fn (string $code, string $name) => ['code' => $code, 'name' => $name],
                array_keys($list),
                $list,
            );
        });
    }

    /**
     * How this country's address form should behave.
     *
     * @return array{
     *     administrative_area_label: string,
     *     postal_code_label: string,
     *     postal_code_pattern: ?string,
     *     postal_code_required: bool,
     *     has_subdivisions: bool,
     *     locality_label: string
     * }
     */
    public function formatFor(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        return cache()->rememberForever("address.format.{$countryCode}", function () use ($countryCode): array {
            $format = $this->formats->get($countryCode);
            $required = $format->getRequiredFields();

            return [
                'administrative_area_label' => $this->humanise($format->getAdministrativeAreaType()) ?: 'State or province',
                'locality_label' => $this->humanise($format->getLocalityType()) ?: 'City',
                'postal_code_label' => $this->humanise($format->getPostalCodeType()) ?: 'Postal code',
                'postal_code_pattern' => $format->getPostalCodePattern(),
                'postal_code_required' => in_array('postalCode', array_map(
                    fn ($f) => is_object($f) ? $f->value : $f,
                    $required,
                ), true),
                'has_subdivisions' => $this->subdivisions($countryCode) !== [],
            ];
        });
    }

    public function isValidCountry(string $countryCode): bool
    {
        return array_key_exists(strtoupper($countryCode), $this->countries->getList('en'));
    }

    /** Whether a subdivision code belongs to the country, when the country has a fixed list. */
    public function isValidSubdivision(string $countryCode, ?string $code): bool
    {
        $subdivisions = $this->subdivisions($countryCode);

        if ($subdivisions === []) {
            return true; // free text; nothing to check against
        }

        return in_array(strtoupper((string) $code), array_column($subdivisions, 'code'), true);
    }

    /** "administrativeArea" / "zip" → "Zip". Library types are camelCase or lowercase. */
    private function humanise(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        return ucfirst(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $type)));
    }
}
