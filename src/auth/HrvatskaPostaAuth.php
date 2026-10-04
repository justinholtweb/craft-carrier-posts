<?php

namespace justinholtweb\carrierposts\auth;

use Craft;
use justinholtweb\carrier\auth\BaseAuth;

/**
 * Hrvatska pošta's username/password → bearer exchange.
 *
 * Not OAuth, so `OAuth2ClientCredentials` does not fit: the body is JSON `{username, password}`,
 * the answer is `{"accessToken": …, "expiresIn": "14400"}` — camelCase, and the lifetime is a
 * *string* of seconds. It also lives on a different port (9000) from everything else (9020),
 * which is why the URL is a constructor argument the carrier computes rather than a path.
 *
 * Like the core strategies, a failed exchange returns no header instead of throwing. The
 * business request then goes out unauthenticated and comes back 401, which the transport
 * classifies like any other refusal — so a wrong password reads as "refused", never as
 * "the label may exist".
 */
class HrvatskaPostaAuth extends BaseAuth
{
    /** Refresh this many seconds early; HP's documentation suggests about five minutes. */
    private const EXPIRY_SLACK = 300;

    public function __construct(
        private string $tokenUrl,
        private string $usernameField = 'username',
        private string $passwordField = 'password',
    ) {
    }

    public function headers(): array
    {
        $token = $this->accessToken();

        return $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting($this->usernameField) !== ''
            && (string)$this->setting($this->passwordField) !== '';
    }

    public function reauthenticate(): bool
    {
        $this->cacheForget('access');

        return $this->accessToken() !== null;
    }

    public function accessToken(): ?string
    {
        $cached = $this->cacheGet('access');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->http()->request('POST', $this->tokenUrl, [
            'json' => [
                'username' => (string)$this->setting($this->usernameField),
                'password' => (string)$this->setting($this->passwordField),
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        if (!$response->ok()) {
            Craft::warning('Hrvatska pošta refused the sign-in: ' . $response->errorMessage(), 'carrier');

            return null;
        }

        $token = (string)$response->at('accessToken', '');

        if ($token === '') {
            return null;
        }

        // "14400", a string. Fall back to the documented four hours if it is missing.
        $expiresIn = (int)($response->at('expiresIn') ?? 14400);
        $this->cacheSet('access', $token, max(60, $expiresIn - self::EXPIRY_SLACK));

        return $token;
    }
}
