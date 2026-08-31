<?php

namespace App\Console\Commands;

use App\Models\Responder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class IssueToken extends Command
{
    protected $signature = 'aman:token {email} {--abilities=read : Comma-separated read,operate,ingest,respond} {--responder= : Link a dedicated responder account by UUID}';

    protected $description = 'Issue an eight-hour development/operator access token (shown only once).';

    public function handle(): int
    {
        $email = $this->argument('email');
        $abilities = array_values(array_unique(explode(',', $this->option('abilities'))));
        if (Validator::make(['email' => $email], ['email' => 'required|email|max:255'])->fails() || array_diff($abilities, ['read', 'operate', 'ingest', 'respond'])) {
            $this->error('Provide a valid email and only read,operate,ingest,respond abilities.');

            return self::FAILURE;
        }
        if (app()->environment('production') && in_array('ingest', $abilities, true)) {
            $this->error('Demo ingestion tokens are not issued in production.');

            return self::FAILURE;
        }
        $responderId = $this->option('responder');
        if (in_array('respond', $abilities, true) && (! $responderId || $abilities !== ['respond'] || ! Responder::whereKey($responderId)->where('authorized', true)->exists())) {
            $this->error('Responder tokens require --abilities=respond and --responder=<authorized responder UUID>.');

            return self::FAILURE;
        }
        if ($responderId && ! in_array('respond', $abilities, true)) {
            $this->error('Use a dedicated respond-only token when linking a responder.');

            return self::FAILURE;
        }
        $existing = User::where('email', $email)->first();
        if ($responderId && (($existing && $existing->responder_id !== $responderId)
            || User::where('responder_id', $responderId)->where('email', '!=', $email)->exists())) {
            $this->error('Use a new dedicated email; existing accounts cannot be rebound.');

            return self::FAILURE;
        }
        if (! $responderId && $existing?->responder_id) {
            $this->error('This is a responder account; issue a respond-only token.');

            return self::FAILURE;
        }
        $user = User::firstOrCreate(['email' => $email], ['name' => $email, 'password' => Str::random(64)]);
        if ($responderId) {
            $user->forceFill(['responder_id' => $responderId])->save();
        }
        $token = $user->createToken('aman-console', $abilities, now()->addHours(8));
        $this->warn('Keep this token private. Do not embed it in a Unity build or commit it. Expires in eight hours.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
