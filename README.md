# omnifood/thefork

TheFork (LaFourchette) for [glitchr/omnifood](https://github.com/glitchr-studio/omnifood): the
restaurant's reservations read with their guests, created, updated and cancelled, its time slots
opened or closed online, the webhooks read - through TheFork's B2B API (TheFork Manager).

> **Not verified against the live API (non vérifié en réel).** TheFork sends its keys only to
> partners it has accepted: this package is written from TheFork's public documentation
> (https://docs.thefork.io/B2B-API/introduction and https://api.thefork.io/manager/openapi.json, read
> on 2026-10-04) and tested against recorded answers.

```php
use Omnifood\TheFork\TheForkPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$thefork = (new TheForkPlatformFactory(HttpClient::create()))->create([
    'client_id' => getenv('THEFORK_CLIENT_ID') ?: null,
    'client_secret' => getenv('THEFORK_CLIENT_SECRET') ?: null,
    'restaurant_id' => getenv('THEFORK_RESTAURANT_ID') ?: null,   // the restaurant's UUID
    'webhook_token' => getenv('THEFORK_WEBHOOK_TOKEN') ?: null,   // the token in the webhook URL given to TheFork
]);
```

Plain PHP, no framework needed: the factory takes any `HttpClientInterface` - the application's, a
`MockHttpClient` in a test - and makes its own when given none. In a Symfony application, the same
options under `omnifood.platforms` ([the bundle](https://github.com/glitchr-studio/omnifood/blob/1.x/docs/symfony.md)):

```yaml
omnifood:
    platforms:
        thefork:
            factory: thefork
            options:
                client_id: '%env(default::THEFORK_CLIENT_ID)%'
                client_secret: '%env(default::THEFORK_CLIENT_SECRET)%'
                restaurant_id: '%env(default::THEFORK_RESTAURANT_ID)%'   # the restaurant's UUID
                webhook_token: '%env(default::THEFORK_WEBHOOK_TOKEN)%'   # the token in the webhook URL given to TheFork
                locale: fr_FR            # a guest's, when not given
                civility: mx             # a guest's (TheFork requires one), when raw['civility'] has none
                with_customers: true     # read each reservation's guest (one call more each)
                # access_token / token_expires_at: a token the site keeps - TheFork asks not to request one needlessly
```

## What it does

| Omnifood | TheFork |
|---|---|
| `reservations($from, $to)` | `GET /v1/reservations?restaurantUuid&startDate&endDate&filterBy=mealDate` (ids, pages), then each |
| `reservation($id)` | `GET /v1/reservations/{id}` and `GET /v1/customers/{customerUuid}` |
| `createReservation($r)` | `POST /v1/restaurants/{id}/reservations` (`mealDate`, `partySize`, `customer`, `offerUuid`, notes) |
| `updateReservation($r)` | `PATCH /v1/reservations/{id}` (`mealDate`, `partySize`, `restaurantNote`) |
| `cancelReservation($id)` | `PATCH /v1/reservations/{id}/cancel` |
| `setStatus($id, CANCELLED)` | the same cancellation |
| `pushAvailability($slots)` | `PUT /v1/restaurants/{id}/availabilities/override` (open or closed, a range) |
| `notify($body, $headers)` | the webhook `{entityType, eventType, uuid, ...}`, its URL token checked |
| `token()`, `refresh()` | `POST https://auth.thefork.io/oauth/token` (Auth0, client credentials, ~2 h 23) |

- Statuses: `RECORDED` is confirmed, and once there `mealStatus` says the service
  (`ARRIVED`, `SEATED`/`BILL`, `LEFT`); `REQUESTED`, `CANCELED`, `NO_SHOW`, `REFUSED` as they are.
- The allergies are the guest's (`allergiesAndIntolerances`), the source `reservationChannel`.
- The webhook carries no data and no event id: `reservation($notification->reference)` reads it.
  TheFork checks nothing but the token the site put in the URL it gave: the controller passes that
  query parameter among the headers as `token`. Answer `{"data": {}}` within a few seconds.
- `pushAvailability()`: TheFork opens or closes slots for online booking (a slot with covers is
  opened, one with 0 closed); it takes no number of covers.
- TheFork takes a phone in E.164 (`+33612345678`) and a locale `ll` or `ll_CC`: both are put in shape.

## Left in NotSupportedException

- `setStatus()` but to cancel: TheFork's public API reads the service's statuses (`status`,
  `mealStatus`) and publishes no call to set arrived, seated, left or no-show
  (https://api.thefork.io/manager/openapi.json).

Not in the public API, so not mapped: tables, deposits and card imprints on a reservation (the
guarantees appear on the time slots only), a reason for a cancellation (TheFork records the
restaurant's as "other").

No `OAuthInterface`: TheFork's B2B API knows client credentials only, no authorization by the
restaurant.

## What it takes

- An e-mail to **integrations@thefork.com** with the company and the use: TheFork sends a
  **client id and secret** through a safe channel, with the scopes it grants
  (`tfm-restaurant:reservations`, `:customers`, `:availabilities`, `:override-availabilities`...).
- The restaurant's **UUID** at TheFork (and its group's), which the public documentation does not say
  where to find: ask TheFork with the keys.
- The **webhook URL** with a token of the site's choosing (`https://site.example/webhooks/thefork?token=...`),
  sent to TheFork, who configures it. The restaurant group may have to accept TheFork's ERB in
  TheFork Manager first.

License: LGPL-3.0-or-later.
