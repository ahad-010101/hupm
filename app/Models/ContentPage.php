<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A public page, as content.  [WP-36, D-27]
 *
 * The slug is the identity and is never edited — it binds to a route declared
 * in `routes/public.php`. Everything else about the page is the office's to
 * change.
 */
class ContentPage extends Model
{
    protected $fillable = [
        'title',
        'nav_label',
        'meta_description',
        'is_published',
        'show_in_nav',
        'nav_position',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'show_in_nav' => 'boolean',
            'nav_position' => 'integer',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('position')->orderBy('id');
    }

    /** What the nav calls this page. */
    public function navLabel(): string
    {
        return $this->nav_label ?: $this->title;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
