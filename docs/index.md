# TheFork with Omnifood

## Installation

```sh
composer require glitchr/omnifood omnifood/thefork
```

## Configuration

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
