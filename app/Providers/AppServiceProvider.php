<?php

namespace App\Providers;

use App\Services\AppSettings;
use App\Services\Sms\AfricasTalkingSmsGateway;
use App\Services\Sms\BeemSmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);

        $this->app->bind(SmsGateway::class, function () {
            $config = config('services.sms');

            return match ($config['driver']) {
                'beem' => new BeemSmsGateway($config['beem']['api_key'], $config['beem']['secret_key'], $config['sender_id']),
                'africastalking' => new AfricasTalkingSmsGateway($config['africastalking']['username'], $config['africastalking']['api_key'], $config['sender_id']),
                default => new LogSmsGateway,
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
