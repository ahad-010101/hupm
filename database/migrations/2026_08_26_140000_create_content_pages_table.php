<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public pages, as content.  [WP-36, DEVIATION D-27]
 *
 * No table backed the public website before this one. FR-PUB-01 calls Available
 * Properties "static content maintained by ADMIN" and never says how, and doc
 * 05 §C2 gives F-13 an em-dash in its Tables column — so "admin-maintained" was
 * a requirement with no implementation path anywhere in the specs.
 *
 * **A row here does not create a route.** Every slug corresponds to a route
 * already declared in `routes/public.php`, and there is no route that resolves
 * an arbitrary slug. That is deliberate: a dynamic `/{slug}` would let somebody
 * publish a page over a path the router already means something by, and would
 * end the property that makes AC-PUB-01 structural rather than merely tested.
 * Adding a page stays a one-line code change; editing one does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();

            // Matches a route name's suffix, not a URL — `/georgia-rental-info`
            // is the slug `georgia`, because the route may be renamed for SEO
            // long after the content is written.
            $table->string('slug', 60)->unique();

            $table->string('title', 150);

            // Null falls back to the title. A nav bar has room for "Repairs"
            // where the page is called "Reporting a repair at your home".
            $table->string('nav_label', 40)->nullable();

            $table->string('meta_description', 255)->nullable();

            // An unpublished page still resolves — it renders its fallback and
            // drops out of the nav. A 404 on a page that exists in the router
            // would be a worse lie than an empty page.
            $table->boolean('is_published')->default(true);

            $table->boolean('show_in_nav')->default(false);
            $table->unsignedSmallInteger('nav_position')->default(0);

            $table->timestamps();

            $table->index(['show_in_nav', 'nav_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
