<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile: the human check of public forms.
 * Off until both keys are in .env (TURNSTILE_SITE_KEY, TURNSTILE_SECRET_KEY).
 */
class Turnstile
{
    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Validation messages show the check under this name. */
    public const FIELD = 'turnstile';

    public function enabled(): bool
    {
        return filled($this->siteKey()) && filled(config('services.turnstile.secret_key'));
    }

    public function siteKey(): ?string
    {
        return config('services.turnstile.site_key') ?: null;
    }

    /**
     * Ask Cloudflare whether the token the widget gave is good. Without an
     * answer the form is refused: a payment form left open is what card
     * testers look for.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        if (blank($token) || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(10)->post(self::VERIFY, array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException $e) {
            Log::warning('Turnstile: Cloudflare could not be reached.', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->json('success') === true;
    }
}
