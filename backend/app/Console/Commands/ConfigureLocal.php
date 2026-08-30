<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConfigureLocal extends Command
{
    protected $signature = 'aman:configure';

    protected $description = 'Add missing local environment settings and generate missing secrets without rotating existing keys.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Manage production secrets through your deployment environment.');

            return self::FAILURE;
        }
        $path = base_path('.env');
        $content = is_file($path) ? file_get_contents($path) : '';
        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $matches) && ! preg_match('/^'.preg_quote($matches[1], '/').'=/m', $content)) {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }
        foreach (['APP_KEY', 'REVERB_APP_SECRET'] as $name) {
            $content = preg_replace_callback('/^'.$name.'=(?:""|\'\')?\s*$/m', fn () => $name.'=base64:'.base64_encode(random_bytes(32)), $content);
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            $this->error('Unable to write .env.');

            return self::FAILURE;
        }
        $this->info('Local settings are ready. Existing secrets were preserved. Configure MySQL credentials, then run php artisan migrate.');

        return self::SUCCESS;
    }
}
