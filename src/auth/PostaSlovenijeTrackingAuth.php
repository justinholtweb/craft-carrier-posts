<?php

namespace justinholtweb\carrierposts\auth;

use Craft;
use justinholtweb\carrier\auth\BaseAuth;

/**
 * Pošta Slovenije's tracking sign-in (the Azure API live since 2025-10-01).
 *
 * Three credentials, none of them standard: HTTP Basic with a GUID user and a GUID password, and
 * a *body* that is a bare JSON string — the "user token" Pošta Slovenije issues with the
 * contract. The answer is the JWT itself as the whole response body, not an object; its lifetime
 * is only in the JWT's `exp` claim.
 *
 * The shape of that answer is read from Pošta Slovenije's own REST-client script, which reads
 * `response.body` directly. It is parsed defensively (a quoted JSON string, a raw `eyJ…` body or
 * an `access_token` object are all accepted) because no response sample is published.
 *
 * `pause()` exists because the transport adds this header to every request it sends, and the
 * unofficial posta.si point feed is on another host that has no business receiving a tracking
 * JWT. See the core-change note in the README.
 */
class PostaSlovenijeTrackingAuth extends BaseAuth
{
    private const EXPIRY_SLACK = 120;

    /** Used when the JWT carries no readable `exp`. */
    private const DEFAULT_TTL = 1800;

    private bool $paused = false;

    public function __construct(
        private string $tokenUrl,
        private string $userField = 'trackingUser',
        private string $passwordField = 'trackingPassword',
        private string $userTokenField = 'trackingUserToken',
    ) {
    }

    public function pause(bool $paused): void
    {
        $this->paused = $paused;
    }

    public function headers(): array
    {
        if ($this->paused) {
            return [];
        }

        $token = $this->accessToken();

        return $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting($this->userField) !== ''
            && (string)$this->setting($this->passwordField) !== ''
            && (string)$this->setting($this->userTokenField) !== '';
    }

    public function reauthenticate(): bool
    {
        if ($this->paused) {
            return false;
        }

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
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->setting($this->userField) . ':' . $this->setting($this->passwordField)),
            ],
            // The body is the user token as a JSON *string*: `"…"`, quotes included.
            'body' => (string)json_encode((string)$this->setting($this->userTokenField)),
        ]);

        if (!$response->ok()) {
            Craft::warning('Pošta Slovenije refused the tracking sign-in: ' . $response->errorMessage(), 'carrier');

            return null;
        }

        $token = self::tokenFrom($response->body);

        if ($token === null) {
            return null;
        }

        $this->cacheSet('access', $token, max(60, self::ttl($token) - self::EXPIRY_SLACK));

        return $token;
    }

    private static function tokenFrom(string $body): ?string
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        if (is_array($decoded)) {
            foreach (['access_token', 'accessToken', 'token'] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }

            return null;
        }

        // A raw, unquoted JWT.
        return preg_match('/^[\w\-]+\.[\w\-]+\.[\w\-]*$/', $body) ? $body : null;
    }

    private static function ttl(string $jwt): int
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return self::DEFAULT_TTL;
        }

        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
        $exp = is_array($claims) ? (int)($claims['exp'] ?? 0) : 0;

        return $exp > time() ? $exp - time() : self::DEFAULT_TTL;
    }
}
