<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use App\Services\AppSettings;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the first administrator from ADMIN_* values in .env and stores
     * default settings. Teacher data is loaded with `php artisan michango:import`.
     */
    public function run(AppSettings $settings): void
    {
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'Mhazini'),
                'password' => env('ADMIN_PASSWORD', 'change-me-now'),
                'role' => 'admin',
            ],
        );

        foreach (AppSettings::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
        $settings->update([]);
    }
}
