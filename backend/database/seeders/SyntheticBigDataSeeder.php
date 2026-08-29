<?php

namespace Database\Seeders;

use App\Services\SyntheticData\GenerationOptions;
use App\Services\SyntheticData\SyntheticDataGenerator;
use Illuminate\Database\Seeder;

final class SyntheticBigDataSeeder extends Seeder
{
    /**
     * Optional programmatic entry point. It is intentionally not registered in
     * DatabaseSeeder and cannot carry the production override factors.
     */
    public function run(): void
    {
        $profile = (string) env('SYNTHETIC_DATA_PROFILE', 'small');
        $seed = (string) env('SYNTHETIC_DATA_SEED', config('synthetic_data.default_seed'));

        app(SyntheticDataGenerator::class)->generate(GenerationOptions::fromInput(
            profile: $profile,
            seed: $seed,
        ));
    }
}
