<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => Asset::class,
            'attachable_id' => Asset::factory(),
            // Lampiran mengikuti organisasi objek yang ditempelinya.
            'organization_id' => fn (array $attributes): ?int => Asset::withoutOrganizationScope()
                ->whereKey($attributes['attachable_id'])
                ->value('organization_id'),
            'type' => Attachment::TYPE_PHOTO,
            'path' => 'assets/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(10_000, 500_000),
            'uploaded_by' => null,
        ];
    }
}
