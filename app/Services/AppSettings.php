<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class AppSettings
{
    public const DEFAULTS = [
        'school_name' => 'Benjamin William Mkapa Sekondari',
        'system_name' => 'Michango ya Ustawi wa Jamii',
        'active_year' => '2026',
        'monthly_amount' => '10000',
        'currency' => 'TZS',
        'reference_prefix' => 'UST',
        'sms_enabled' => '1',
        'email_enabled' => '1',
        'notify_on_update' => '0',
        'message_template' => 'Ndugu {jina}, tumepokea mchango wako wa Ustawi wa Jamii wa {kiasi} kwa mwezi {mwezi} {mwaka} (tarehe {tarehe}). Kumbukumbu: {kumbukumbu}. Jumla ya michango yako {mwaka}: {jumla}. Asante. - {shule}',
    ];

    public const BOOLEANS = ['sms_enabled', 'email_enabled', 'notify_on_update'];

    public const INTEGERS = ['active_year', 'monthly_amount'];

    public function all(): array
    {
        $stored = Cache::rememberForever('app_settings', fn () => Setting::pluck('value', 'key')->all());
        $values = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));

        foreach ($values as $key => $value) {
            if (in_array($key, self::BOOLEANS, true)) {
                $values[$key] = (bool) (int) $value;
            } elseif (in_array($key, self::INTEGERS, true)) {
                $values[$key] = (int) $value;
            }
        }

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function update(array $values): array
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
        Cache::forget('app_settings');

        return $this->all();
    }

    public function monthlyAmount(): int
    {
        return max(1, (int) $this->get('monthly_amount'));
    }
}
