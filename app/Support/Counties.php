<?php

namespace App\Support;

use App\Domain\Notifications\WeatherAlertService;

/**
 * Counties, by state.  [D-19, FR-NTF-03]
 *
 * Not reference data we can look up: `nnjeim/world` carries countries, states
 * and cities, and stops there. So the one state this portfolio operates in is
 * listed here, and every other state falls back to a free-text box.
 *
 * The county is not decoration. The National Weather Service describes an
 * alert's area as a semicolon-separated list of county names — "Fulton; DeKalb;
 * Cobb" — and {@see WeatherAlertService} matches a
 * property to an alert on that string alone, because there is no geocoder on
 * this host. A property with no county recorded is treated as covered by every
 * state-wide alert, which is the safe direction to be wrong in but produces
 * warnings that did not apply.
 *
 * Names are stored exactly as the NWS writes them, without the word "County"
 * — that is what makes the match exact rather than fuzzy.
 */
class Counties
{
    /**
     * Georgia's 159 counties, alphabetically.
     *
     * Second only to Texas, and the reason this is a select rather than a text
     * box: "DeKalb" is spelt four different ways by people typing quickly, and
     * each misspelling is a property that silently stops matching its alerts.
     *
     * @var list<string>
     */
    private const GEORGIA = [
        'Appling', 'Atkinson', 'Bacon', 'Baker', 'Baldwin', 'Banks', 'Barrow',
        'Bartow', 'Ben Hill', 'Berrien', 'Bibb', 'Bleckley', 'Brantley',
        'Brooks', 'Bryan', 'Bulloch', 'Burke', 'Butts', 'Calhoun', 'Camden',
        'Candler', 'Carroll', 'Catoosa', 'Charlton', 'Chatham', 'Chattahoochee',
        'Chattooga', 'Cherokee', 'Clarke', 'Clay', 'Clayton', 'Clinch', 'Cobb',
        'Coffee', 'Colquitt', 'Columbia', 'Cook', 'Coweta', 'Crawford', 'Crisp',
        'Dade', 'Dawson', 'Decatur', 'DeKalb', 'Dodge', 'Dooly', 'Dougherty',
        'Douglas', 'Early', 'Echols', 'Effingham', 'Elbert', 'Emanuel', 'Evans',
        'Fannin', 'Fayette', 'Floyd', 'Forsyth', 'Franklin', 'Fulton', 'Gilmer',
        'Glascock', 'Glynn', 'Gordon', 'Grady', 'Greene', 'Gwinnett',
        'Habersham', 'Hall', 'Hancock', 'Haralson', 'Harris', 'Hart', 'Heard',
        'Henry', 'Houston', 'Irwin', 'Jackson', 'Jasper', 'Jeff Davis',
        'Jefferson', 'Jenkins', 'Johnson', 'Jones', 'Lamar', 'Lanier',
        'Laurens', 'Lee', 'Liberty', 'Lincoln', 'Long', 'Lowndes', 'Lumpkin',
        'Macon', 'Madison', 'Marion', 'McDuffie', 'McIntosh', 'Meriwether',
        'Miller', 'Mitchell', 'Monroe', 'Montgomery', 'Morgan', 'Murray',
        'Muscogee', 'Newton', 'Oconee', 'Oglethorpe', 'Paulding', 'Peach',
        'Pickens', 'Pierce', 'Pike', 'Polk', 'Pulaski', 'Putnam', 'Quitman',
        'Rabun', 'Randolph', 'Richmond', 'Rockdale', 'Schley', 'Screven',
        'Seminole', 'Spalding', 'Stephens', 'Stewart', 'Sumter', 'Talbot',
        'Taliaferro', 'Tattnall', 'Taylor', 'Telfair', 'Terrell', 'Thomas',
        'Tift', 'Toombs', 'Towns', 'Treutlen', 'Troup', 'Turner', 'Twiggs',
        'Union', 'Upson', 'Walker', 'Walton', 'Ware', 'Warren', 'Washington',
        'Wayne', 'Webster', 'Wheeler', 'White', 'Whitfield', 'Wilcox', 'Wilkes',
        'Wilkinson', 'Worth',
    ];

    /** @var array<string, list<string>> */
    private const BY_STATE = ['Georgia' => self::GEORGIA];

    /**
     * The counties of a state, or an empty list where we hold none.
     *
     * An empty list is a real answer, not a failure: the form reads it as
     * "offer a text box instead", so a property outside Georgia can still
     * record a county and still match its alerts.
     *
     * @return list<string>
     */
    public static function forState(?string $state): array
    {
        return self::BY_STATE[trim((string) $state)] ?? [];
    }

    /** Do we hold a list for this state at all? */
    public static function known(?string $state): bool
    {
        return self::forState($state) !== [];
    }

    /**
     * Is this a county of that state?
     *
     * True for any county where we hold no list — there is nothing to check it
     * against, and rejecting an address we cannot verify would be worse than
     * accepting an odd one (the same reasoning as the postal-code rule).
     */
    public static function valid(?string $state, ?string $county): bool
    {
        $county = trim((string) $county);

        if ($county === '' || ! self::known($state)) {
            return true;
        }

        return in_array($county, self::forState($state), true);
    }
}
