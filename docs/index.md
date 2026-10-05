# TheFork with Omnifood

## Installation

```sh
composer require glitchr/omnifood omnifood/thefork
```

## Configuration

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

The factory takes any `HttpClientInterface` (the application's, a `MockHttpClient` in a test) and
makes its own when given none; several platforms go in a `Registry`
([the core's installation](https://github.com/glitchr-studio/omnifood/blob/1.x/docs/installation.md)).
In a Symfony application, the same options in `config/packages/omnifood.yaml`:

```yaml
omnifood:
    platforms:
        thefork:
            factory: thefork
            options:
                client_id: '%env(default::THEFORK_CLIENT_ID)%'
                client_secret: '%env(default::THEFORK_CLIENT_SECRET)%'
                restaurant_id: '%env(default::THEFORK_RESTAURANT_ID)%'
                webhook_token: '%env(default::THEFORK_WEBHOOK_TOKEN)%'
```

Send TheFork the webhook URL with the token: `https://site.example/webhooks/thefork?token=<THEFORK_WEBHOOK_TOKEN>`.

## The day's book

```php
$today = $thefork->reservations(new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow'));
foreach ($today as $r) {
    printf("%s  %d  %s  %s\n", $r->date->format('H:i'), $r->covers, $r->guest->name(), $r->allergies);
}
```

## A reservation taken by phone

```php
$recorded = $thefork->createReservation(new Reservation(
    new \DateTimeImmutable('2026-10-12 19:30'), 4,
    new Guest('Jo', 'Martin', 'jo@example.com', '+33 6 12 34 56 78', 'fr'),
    notes: 'A high chair',
));
$thefork->updateReservation($recorded->with(covers: 5));
$thefork->cancelReservation($recorded->reference);
```

## The webhook

```php
try {
    $notification = $thefork->notify($request->getContent(), $request->headers->all() + ['token' => (string) $request->query->get('token')]);
} catch (InvalidSignatureException) {
    return new JsonResponse(['error' => 'unauthorized'], 401);
}
if (NotificationSubject::RESERVATION === $notification->subject) {
    $bus->dispatch(new SyncReservation('thefork', $notification->reference));   // reservation() reads it
}

return new JsonResponse(['data' => new \stdClass()]);
```

## Online slots

```php
$thefork->pushAvailability([
    new Slot(new \DateTimeImmutable('2026-10-10 19:00'), 0, new \DateTimeImmutable('2026-10-10 21:30')),  // closed online
]);
```

Not verified against the live API.
