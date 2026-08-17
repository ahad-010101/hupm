<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full postal address on properties.  [DEVIATION D-19]
 *
 * DB §A3 models a US-only address: `state CHAR(2)`, `zip CHAR(5)`, no country.
 * That matches the portfolio (25 properties in metropolitan Atlanta) but makes
 * the form unable to express an address the way a normal form does.
 *
 * Three columns change shape, and each has a reason beyond tidiness:
 *
 *   country_code    Added, CHAR(2), default 'US'. Everything else here is
 *                   meaningless without it — "GA" is Georgia in the US and
 *                   Gabon as an ISO country.
 *   state           CHAR(2) → VARCHAR(64). Two characters holds a US state or
 *                   a Canadian province and nothing else; Japanese prefectures
 *                   and Italian provinces use longer codes, and several
 *                   countries have no subdivision code at all, only a name.
 *   zip → postal_code
 *                   Renamed. A column called `zip` holding "SW1A 1AA" is a lie,
 *                   and this schema's whole discipline (D-01…D-04) is that it
 *                   does not lie about what it stores. CHAR(5) → VARCHAR(16).
 *                   **Where DB §A3 says `zip`, read `postal_code`.**
 *
 * The index moves with the rename. WP-21 groups by it to poll the NWS once per
 * area — and note that the NWS covers the United States only, so a property
 * outside it will have no weather alerts regardless of what is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->char('country_code', 2)->default('US')->after('name');
            $table->string('address_line_2', 255)->nullable()->after('street_address');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->renameColumn('zip', 'postal_code');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('state', 64)->default('GA')->change();
            $table->string('postal_code', 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->renameColumn('postal_code', 'zip');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->char('state', 2)->default('GA')->change();
            $table->char('zip', 5)->change();
            $table->dropColumn(['country_code', 'address_line_2']);
        });
    }
};
