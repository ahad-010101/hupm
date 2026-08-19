<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $filename = fake()->slug(3).'.pdf';

        return [
            'tenant_id' => Tenant::factory(),
            'category' => 'correspondence',
            'title' => fake()->sentence(3),
            'original_filename' => $filename,
            // Outside the web root, UUID filename. WP-17 puts a real file there.
            'stored_path' => 'documents/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'sha256' => hash('sha256', $filename),
            'visible_to_tenant' => true,
        ];
    }

    public function hiddenFromTenant(): static
    {
        return $this->state(fn () => ['visible_to_tenant' => false]);
    }
}
