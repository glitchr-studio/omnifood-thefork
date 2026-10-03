<?php

namespace Omnifood\TheFork;

use Omnifood\Config;
use Omnifood\Model\Token;
use Omnifood\PlatformFactory;
use Omnifood\PlatformInterface;

/**
 * TheFork (LaFourchette), through its B2B API - TheFork Manager's:
 *
 *   options:
 *     client_id: '%env(default::THEFORK_CLIENT_ID)%'         # sent by TheFork (integrations@thefork.com)
 *     client_secret: '%env(default::THEFORK_CLIENT_SECRET)%'
 *     restaurant_id: '%env(default::THEFORK_RESTAURANT_ID)%' # the restaurant's UUID at TheFork
 *     webhook_token: '%env(default::THEFORK_WEBHOOK_TOKEN)%' # the token put in the webhook URL given to TheFork (?token=...)
 *     access_token: ~                                        # a token kept by the site (refresh(), token()), and when it dies:
 *     token_expires_at: ~                                    # ATOM; TheFork asks not to ask for a new one before
 *     locale: fr_FR                                          # a guest's, when the reservation does not say
 *     civility: mx                                           # a guest's, required by TheFork, when the reservation's raw has none
 *     with_customers: true                                   # read each reservation's customer (name, phone, allergies): one call more each
 *     base_uri: https://api.thefork.io/manager
 *     token_url: https://auth.thefork.io/oauth/token
 */
final class TheForkPlatformFactory extends PlatformFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults([
            'omnifood.factory_name' => 'thefork',
            'omnifood.required_options' => [],
            'client_id' => null,
            'client_secret' => null,
            'restaurant_id' => null,
            'webhook_token' => null,
            'access_token' => null,
            'token_expires_at' => null,
            'locale' => 'fr_FR',
            'civility' => 'mx',
            'with_customers' => true,
            'base_uri' => Api::BASE_URI,
            'token_url' => Api::TOKEN_URL,
        ]);
    }

    protected function build(Config $config): PlatformInterface
    {
        $token = $config['access_token'] ? new Token((string) $config['access_token'], $config['token_expires_at'] ? new \DateTimeImmutable((string) $config['token_expires_at']) : null) : null;

        return new TheForkPlatform(
            new Api($config['client_id'] ? (string) $config['client_id'] : null, $config['client_secret'] ? (string) $config['client_secret'] : null, $token, (string) $config['base_uri'], (string) $config['token_url'], $this->http),
            $config['restaurant_id'] ? (string) $config['restaurant_id'] : null,
            $config['webhook_token'] ? (string) $config['webhook_token'] : null,
            (string) $config['locale'],
            (string) $config['civility'],
            filter_var($config['with_customers'], \FILTER_VALIDATE_BOOL),
        );
    }
}
