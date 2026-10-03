<?php

namespace Omnifood\TheFork;

use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Omnifood\Model\Token;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TheFork's B2B API (api.thefork.io/manager), thin: JSON calls with a
 * Bearer token from TheFork's Auth0 (client credentials, audience
 * https://api.thefork.io), held until it dies - TheFork asks not to
 * request a new one before - and its two error shapes as Omnifood's:
 * {"data": {"code"}, "error", "statusCode"} from the API,
 * {"message"} from the gateway; 401 and 403 as UnauthorizedException.
 */
final class Api
{
    public const PLATFORM = 'thefork';
    public const BASE_URI = 'https://api.thefork.io/manager';
    public const TOKEN_URL = 'https://auth.thefork.io/oauth/token';
    public const AUDIENCE = 'https://api.thefork.io';

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private ?Token $token = null,
        private readonly string $baseUri = self::BASE_URI,
        private readonly string $tokenUrl = self::TOKEN_URL,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    /**
     * @param array<string, scalar|null>        $query
     * @param array<string, mixed>|object|null $json
     *
     * @return array<mixed> the answer, [] when it has none
     */
    public function request(string $method, string $path, array $query = [], array|object|null $json = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->token()->accessToken, 'Accept' => 'application/json']];
        if ($query = array_filter($query, static fn ($v) => null !== $v && '' !== $v)) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }

        return $this->send($method, rtrim($this->baseUri, '/').'/'.ltrim($path, '/'), $options);
    }

    /** The token held, a fresh one when there is none or it is about to die. */
    public function token(): Token
    {
        if (null === $this->token || $this->token->isExpired(new \DateTimeImmutable('+60 seconds'))) {
            $this->refresh();
        }

        return $this->token;
    }

    public function heldToken(): ?Token
    {
        return $this->token;
    }

    public function refresh(): Token
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new InvalidConfigException('The "thefork" platform needs: client_id, client_secret.');
        }
        $data = $this->send('POST', $this->tokenUrl, ['body' => [
            'audience' => self::AUDIENCE,
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]]);
        if (!isset($data['access_token'])) {
            throw new ProviderException(self::PLATFORM, 'No token in TheFork\'s answer.');
        }

        return $this->token = new Token(
            (string) $data['access_token'],
            isset($data['expires_in']) ? new \DateTimeImmutable('+'.(int) $data['expires_in'].' seconds') : null,
            null,
            isset($data['scope']) ? array_values(array_filter(explode(' ', (string) $data['scope']))) : [],
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function send(string $method, string $url, array $options): array
    {
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PLATFORM, 'TheFork could not be reached: '.$e->getMessage(), null, null, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        $data = \is_array($data) ? $data : [];
        if ($status >= 400) {
            throw self::error($status, $data);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function error(int $status, array $data): ProviderException
    {
        $code = isset($data['data']['code']) && '' !== $data['data']['code'] ? (string) $data['data']['code'] : (isset($data['error']) && \is_string($data['error']) && 401 <= $status && $status <= 403 ? $data['error'] : null);
        $message = (string) ($data['message'] ?? $data['error_description'] ?? $code ?? $data['error'] ?? \sprintf('HTTP %d.', $status));

        if (401 === $status || 403 === $status) {
            return new UnauthorizedException(self::PLATFORM, $message, $status, $code);
        }

        return new ProviderException(self::PLATFORM, 429 === $status ? 'Too many calls, try again later: '.$message : $message, $status, $code);
    }
}
