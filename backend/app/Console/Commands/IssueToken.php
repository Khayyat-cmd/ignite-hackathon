<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class IssueToken extends Command
{
    protected $signature = 'aman:token {email} {--abilities=read : Comma-separated read,operate,ingest}';

    protected $description = 'Issue an eight-hour development/operator access token (shown only once).';

    public function handle(): int
    {
        $email = $this->argument('email');
        $abilities = array_values(array_unique(explode(',', $this->option('abilities'))));
        if (Validator::make(['email' => $email], ['email' => 'required|email|max:255'])->fails() || array_diff($abilities, ['read', 'operate', 'ingest'])) {
            $this->error('Provide a valid email and only read,operate,ingest abilities.');

            return self::FAILURE;
        }
        if (app()->environment('production') && in_array('ingest', $abilities, true)) {
            $this->error('Demo ingestion tokens are not issued in production.');

            return self::FAILURE;
        }
        $user = User::firstOrCreate(['email' => $email], ['name' => $email, 'password' => Str::random(64)]);
        $token = $user->createToken('aman-console', $abilities, now()->addHours(8));
        $this->warn('Keep this token private. Do not embed it in a Unity build or commit it. Expires in eight hours.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
