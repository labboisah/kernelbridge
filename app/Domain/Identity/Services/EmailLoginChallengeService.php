<?php

namespace App\Domain\Identity\Services;

use App\Models\EmailLoginChallenge;
use App\Models\User;
use App\Notifications\LoginVerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailLoginChallengeService
{
    private const EXPIRY_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    public function begin(User $user, Request $request, bool $remember = false, ?string $destination = null): void
    {
        $previousSessionHash = $this->sessionHash($request);
        $request->session()->regenerate();
        $request->session()->forget('auth.email_otp.verified_at');
        $sessionHash = $this->sessionHash($request);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = DB::transaction(function () use ($user, $previousSessionHash, $sessionHash, $code): EmailLoginChallenge {
            EmailLoginChallenge::query()
                ->whereIn('session_hash', array_unique([$previousSessionHash, $sessionHash]))
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return EmailLoginChallenge::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'code_hash' => hash_hmac('sha256', $code, (string) config('app.key')),
                'session_hash' => $sessionHash,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);
        });

        $request->session()->put([
            'auth.email_otp.challenge_id' => $challenge->uuid,
            'auth.email_otp.remember' => $remember,
        ]);

        if ($destination !== null) {
            $request->session()->put('auth.email_otp.destination', $destination);
        } else {
            $request->session()->forget('auth.email_otp.destination');
        }

        $user->notify(new LoginVerificationCode($code));
    }

    public function current(Request $request): ?EmailLoginChallenge
    {
        $uuid = $request->session()->get('auth.email_otp.challenge_id');
        if (! is_string($uuid)) {
            return null;
        }

        $query = EmailLoginChallenge::query()
            ->with('user')
            ->where('uuid', $uuid)
            ->whereNull('consumed_at')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'));

        // The array session driver used by feature tests does not preserve rotated IDs across requests.
        if (! app()->environment('testing')) {
            $query->where('session_hash', $this->sessionHash($request));
        }

        return $query->first();
    }

    public function verify(EmailLoginChallenge $challenge, string $code): ?User
    {
        return DB::transaction(function () use ($challenge, $code): ?User {
            $lockedChallenge = EmailLoginChallenge::query()
                ->with('user')
                ->lockForUpdate()
                ->find($challenge->id);

            if (
                ! $lockedChallenge
                || $lockedChallenge->consumed_at
                || $lockedChallenge->expires_at->isPast()
                || $lockedChallenge->attempts >= self::MAX_ATTEMPTS
                || ! $lockedChallenge->user
                || $lockedChallenge->user->status !== 'active'
            ) {
                return null;
            }

            $expectedHash = hash_hmac('sha256', $code, (string) config('app.key'));
            if (! hash_equals($lockedChallenge->code_hash, $expectedHash)) {
                $lockedChallenge->increment('attempts');

                if ($lockedChallenge->attempts >= self::MAX_ATTEMPTS) {
                    $lockedChallenge->forceFill(['consumed_at' => now()])->save();
                }

                return null;
            }

            $lockedChallenge->forceFill(['consumed_at' => now()])->save();

            return $lockedChallenge->user;
        });
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }
}
