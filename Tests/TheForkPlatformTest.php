<?php

namespace Omnifood\TheFork\Tests;

use Omnifood\Channel;
use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Exception\NotSupportedException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Omnifood\Model\Guest;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\Reservation;
use Omnifood\Model\ReservationStatus;
use Omnifood\Model\Slot;
use Omnifood\TheFork\TheForkPlatform;
use Omnifood\TheFork\TheForkPlatformFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TheForkPlatformTest extends TestCase
{
    private const RESTAURANT = '565426e9-2b3a-4a39-9d7a-2f1f3f5a1e10';

    /** @var list<array{string, string, array<string, string>, array<string, string>, mixed}> method, path, query, headers, body */
    private array $calls = [];

    private int $tokens = 0;

    /** @var array<string, array<string, mixed>> */
    private array $reservations = [];

    private function platform(array $options = [], ?\Closure $answer = null): TheForkPlatform
    {
        $this->reservations = [
            'r1' => self::reservation('r1', '2026-10-10T20:00:00+02:00', 'RECORDED', null, 'c1'),
            'r2' => self::reservation('r2', '2026-10-10T12:30:00+02:00', 'RECORDED', 'SEATED', null),
            'r3' => self::reservation('r3', '2026-10-11T23:30:00+02:00', 'REQUESTED', null, null),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $headers = [];
            foreach ($options['headers'] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $body = $options['body'] ?? '';
            if ('auth.thefork.io' === parse_url($url, \PHP_URL_HOST)) {
                parse_str(\is_string($body) ? $body : '', $form);
                $this->calls[] = [$method, $path, [], $headers, $form];

                return self::json(['access_token' => 'tf-token-'.++$this->tokens, 'scope' => 'tfm-restaurant:reservations tfm-restaurant:customers', 'expires_in' => 8600, 'token_type' => 'Bearer']);
            }
            $this->calls[] = [$method, $path, $query, $headers, \is_string($body) && '' !== $body ? json_decode($body, true) : null];
            if ($answer && null !== ($response = $answer($method, $path))) {
                return $response;
            }

            return match (true) {
                'GET' === $method && '/manager/v1/reservations' === $path => self::json(['data' => '1' === ($query['page'] ?? '1') ? ['r1', 'r2'] : ['r3'], 'totalCount' => 101, 'page' => (int) ($query['page'] ?? 1), 'limit' => 2]),
                'GET' === $method && preg_match('#^/manager/v1/reservations/(r\d)$#', $path, $m) => self::json($this->reservations[$m[1]]),
                'GET' === $method && '/manager/v1/customers/c1' === $path => self::json(['customerUuid' => 'c1', 'email' => 'aiko@example.com', 'firstName' => 'Aiko', 'lastName' => 'Tanaka', 'phone' => '+33612345678', 'locale' => 'fr_FR', 'allergiesAndIntolerances' => ['Peanuts', 'Shellfish'], 'notes' => '']),
                'POST' === $method && '/manager/v1/restaurants/'.self::RESTAURANT.'/reservations' === $path,
                'PATCH' === $method && '/manager/v1/reservations/r1' === $path => self::json(['reservationUuid' => 'r9', 'restaurantUuid' => self::RESTAURANT, 'mealDate' => '2026-10-12T19:30:00+02:00', 'partySize' => 4, 'status' => 'RECORDED', 'reservationChannel' => 'TheFork Manager API', 'createdAt' => '2026-10-04T08:00:00Z',
                    'customer' => ['customerUuid' => 'c9', 'email' => 'jo@example.com', 'firstName' => 'Jo', 'lastName' => 'Martin', 'phone' => '+33612345678', 'locale' => 'fr_FR', 'civility' => 'mx']]),
                'PATCH' === $method && '/manager/v1/reservations/r1/cancel' === $path => self::json(['reservationUuid' => 'r1', 'status' => 'CANCELED']),
                'PUT' === $method && str_ends_with($path, '/availabilities/override') => self::json(['date' => '2026-10-10', 'startTime' => '19:00', 'isOpen' => false, 'restaurantUuid' => self::RESTAURANT]),
                default => self::json(['data' => ['code' => 'RESERVATION_NOT_FOUND'], 'error' => 'Not Found', 'statusCode' => 404], 404),
            };
        });

        return (new TheForkPlatformFactory($http))->create($options + ['client_id' => 'cid', 'client_secret' => 'secret', 'restaurant_id' => self::RESTAURANT, 'webhook_token' => 'NN8ul7c3wRE0hYP2XPOhyYDRQnyHFMJq']);
    }

    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status]);
    }

    private static function reservation(string $id, string $date, string $status, ?string $mealStatus, ?string $customer): array
    {
        return ['reservationUuid' => $id, 'restaurantUuid' => self::RESTAURANT, 'mealDate' => $date, 'mealStatus' => $mealStatus, 'partySize' => 2, 'status' => $status, 'offerUuid' => null, 'customerNote' => 'A birthday', 'restaurantNote' => 'By the window', 'customerUuid' => $customer, 'reservationChannel' => 'TheFork', 'createdAt' => '2026-10-01T10:00:00Z', 'updatedAt' => null];
    }

    public function testTheReservationsOfAPeriodPageByPageWithTheirGuests(): void
    {
        $platform = $this->platform();
        $reservations = $platform->reservations(new \DateTimeImmutable('2026-10-10T00:00:00+02:00'), new \DateTimeImmutable('2026-10-10T23:59:59+02:00'));

        self::assertSame(['r2', 'r1'], array_map(static fn (Reservation $r) => $r->reference, $reservations), 'oldest first; r3 is the next day');
        [$lunch, $dinner] = $reservations;
        self::assertSame(ReservationStatus::SEATED, $lunch->status);
        self::assertSame(ReservationStatus::CONFIRMED, $dinner->status);
        self::assertSame('Aiko Tanaka', $dinner->guest->name());
        self::assertSame('+33612345678', $dinner->guest->phone);
        self::assertSame('Peanuts, Shellfish', $dinner->allergies);
        self::assertSame('A birthday', $dinner->notes);
        self::assertSame('By the window', $dinner->raw['restaurantNote']);
        self::assertSame(Channel::THEFORK, $dinner->channel);
        self::assertSame('TheFork', $dinner->source);
        self::assertSame(self::RESTAURANT, $dinner->restaurant);
        self::assertEquals(new \DateTimeImmutable('2026-10-10T20:00:00+02:00'), $dinner->date);

        [$token, $page1] = $this->calls;
        self::assertSame(['POST', '/oauth/token'], [$token[0], $token[1]]);
        self::assertSame(['audience' => 'https://api.thefork.io', 'grant_type' => 'client_credentials', 'client_id' => 'cid', 'client_secret' => 'secret'], $token[4]);
        self::assertSame('/manager/v1/reservations', $page1[1]);
        self::assertSame(['restaurantUuid' => self::RESTAURANT, 'startDate' => '2026-10-09', 'endDate' => '2026-10-10', 'filterBy' => 'mealDate', 'limit' => '100', 'page' => '1'], $page1[2], 'UTC days');
        self::assertSame('Bearer tf-token-1', $page1[3]['authorization']);
        self::assertSame(1, $this->tokens, 'one token for every call');
    }

    public function testTheStatusesAndTheServiceAsItGoes(): void
    {
        self::assertSame(ReservationStatus::CONFIRMED, TheForkPlatform::status('RECORDED'));
        self::assertSame(ReservationStatus::ARRIVED, TheForkPlatform::status('RECORDED', 'PARTIALLY_ARRIVED'));
        self::assertSame(ReservationStatus::SEATED, TheForkPlatform::status('RECORDED', 'BILL'));
        self::assertSame(ReservationStatus::FINISHED, TheForkPlatform::status('RECORDED', 'LEFT'));
        self::assertSame(ReservationStatus::REQUESTED, TheForkPlatform::status('REQUESTED'));
        self::assertSame(ReservationStatus::CANCELLED, TheForkPlatform::status('CANCELED'));
        self::assertSame(ReservationStatus::NO_SHOW, TheForkPlatform::status('NO_SHOW'));
        self::assertSame(ReservationStatus::REFUSED, TheForkPlatform::status('REFUSED'));
        self::assertSame(ReservationStatus::UNKNOWN, TheForkPlatform::status('WHATEVER'));
    }

    public function testAReservationTakenByPhoneIsRecorded(): void
    {
        $platform = $this->platform();
        $taken = new Reservation(new \DateTimeImmutable('2026-10-12T19:30:00+02:00'), 4, new Guest('Jo', 'Martin', 'jo@example.com', '+33 6 12 34 56 78', 'fr-fr'), notes: 'A high chair', raw: ['restaurantNote' => 'Regular']);

        $recorded = $platform->createReservation($taken);

        self::assertSame('r9', $recorded->reference);
        self::assertSame('Jo Martin', $recorded->guest->name());
        self::assertSame('c9', $recorded->guest->id);
        self::assertSame('TheFork Manager API', $recorded->source);
        $body = $this->calls[1][4];
        self::assertSame('/manager/v1/restaurants/'.self::RESTAURANT.'/reservations', $this->calls[1][1]);
        self::assertSame(['mealDate' => '2026-10-12T19:30:00+02:00', 'partySize' => 4, 'customerNote' => 'A high chair', 'restaurantNote' => 'Regular',
            'customer' => ['email' => 'jo@example.com', 'firstName' => 'Jo', 'lastName' => 'Martin', 'phone' => '+33612345678', 'locale' => 'fr_FR', 'civility' => 'mx']], $body);
    }

    public function testAReservationUpdatedAndCancelled(): void
    {
        $platform = $this->platform();
        $read = $platform->reservation('r1');
        $platform->updateReservation($read->with(covers: 4, date: new \DateTimeImmutable('2026-10-12T19:30:00+02:00')));
        $platform->cancelReservation('r1', 'The guest called');
        $platform->setStatus('r1', ReservationStatus::CANCELLED);

        $patch = array_values(array_filter($this->calls, static fn ($c) => 'PATCH' === $c[0]));
        self::assertSame(['mealDate' => '2026-10-12T19:30:00+02:00', 'partySize' => 4, 'restaurantNote' => 'By the window'], $patch[0][4], 'the staff note sent back as it was');
        self::assertSame(['/manager/v1/reservations/r1/cancel', null], [$patch[1][1], $patch[1][4]], 'no reason: TheFork takes none');
        self::assertSame('/manager/v1/reservations/r1/cancel', $patch[2][1]);
    }

    public function testTheServicesStatusesAreNotWritable(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->platform()->setStatus('r1', ReservationStatus::SEATED);
    }

    public function testSlotsOpenedOrClosedOnline(): void
    {
        $platform = $this->platform();
        $platform->pushAvailability([
            new Slot(new \DateTimeImmutable('2026-10-10 19:00'), 0, new \DateTimeImmutable('2026-10-10 21:30')),
            new Slot(new \DateTimeImmutable('2026-10-11 12:00'), 6),
        ]);

        self::assertSame(['date' => '2026-10-10', 'startTime' => '19:00', 'endTime' => '21:30', 'isOpen' => false], $this->calls[1][4]);
        self::assertSame(['date' => '2026-10-11', 'startTime' => '12:00', 'isOpen' => true], $this->calls[2][4]);
        self::assertSame('/manager/v1/restaurants/'.self::RESTAURANT.'/availabilities/override', $this->calls[1][1]);
    }

    public function testAWebhookSaysWhatToReadAgain(): void
    {
        $platform = $this->platform();
        $body = '{"entityType":"reservation","eventType":"reservationCreated","uuid":"a288660b","groupUuid":"c1fa3225","restaurantUuid":"565426e9"}';

        $notification = $platform->notify($body, ['Content-Type' => 'application/json', 'token' => 'NN8ul7c3wRE0hYP2XPOhyYDRQnyHFMJq']);

        self::assertSame(['reservationCreated', NotificationSubject::RESERVATION, 'a288660b', '565426e9'], [$notification->event, $notification->subject, $notification->reference, $notification->store]);
        self::assertNull($notification->id, 'TheFork gives none');
        self::assertNull($notification->reservation, 'to be read again');
        self::assertSame(NotificationSubject::OTHER, $platform->notify('{"entityType":"review","eventType":"reviewRatingCreated","uuid":"x"}', ['token' => 'NN8ul7c3wRE0hYP2XPOhyYDRQnyHFMJq'])->subject);

        $this->expectException(InvalidSignatureException::class);
        $platform->notify($body, ['token' => 'guessed']);
    }

    public function testTheTokenIsKeptUntilItDiesAndGivenBack(): void
    {
        $platform = $this->platform(['access_token' => 'kept', 'token_expires_at' => (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM)]);
        $platform->reservation('r2');
        self::assertSame(0, $this->tokens, 'the kept token used');
        self::assertSame('Bearer kept', $this->calls[0][3]['authorization']);

        $fresh = $platform->refresh();
        self::assertSame('tf-token-1', $fresh->accessToken);
        self::assertSame(['tfm-restaurant:reservations', 'tfm-restaurant:customers'], $fresh->scopes);
        self::assertFalse($fresh->isExpiring(0, new \DateTimeImmutable('+2 hours')));
        self::assertSame($fresh, $platform->token());

        $expired = $this->platform(['access_token' => 'old', 'token_expires_at' => '2020-01-01T00:00:00+00:00']);
        $this->calls = [];
        $expired->reservation('r2');
        self::assertSame('/oauth/token', $this->calls[0][1], 'a dead token replaced first');
    }

    public function testErrorsAreMapped(): void
    {
        try {
            $this->platform()->reservation('nope');
            self::fail('Not found.');
        } catch (ProviderException $e) {
            self::assertSame('RESERVATION_NOT_FOUND', $e->providerCode);
            self::assertSame(404, $e->status);
        }
        try {
            $this->platform([], static fn () => self::json(['message' => 'Forbidden'], 403))->reservation('r1');
            self::fail('A scope missing.');
        } catch (UnauthorizedException $e) {
            self::assertSame('[thefork] Forbidden', $e->getMessage());
        }
        try {
            $this->platform([], static fn (string $m, string $p) => str_ends_with($p, '/reservations') ? self::json(['data' => ['code' => 'NO_AVAILABILITY'], 'error' => 'Bad Request', 'statusCode' => 400], 400) : null)
                ->createReservation(new Reservation(new \DateTimeImmutable('+1 day'), 2, new Guest('A', 'B', 'a@b.c')));
            self::fail('No table.');
        } catch (ProviderException $e) {
            self::assertSame('NO_AVAILABILITY', $e->providerCode);
        }
    }

    public function testWithoutKeysOnlyWhatNeedsNoneWorks(): void
    {
        $platform = (new TheForkPlatformFactory(new MockHttpClient()))->create(['restaurant_id' => self::RESTAURANT]);

        self::assertTrue($platform->capabilities()->availabilityPush);
        self::assertNull($platform->token());
        try {
            $platform->notify('{}', []);
            self::fail('No token to check the webhook against.');
        } catch (InvalidConfigException) {
        }
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "thefork" platform needs: client_id, client_secret.');
        $platform->reservation('r1');
    }
}
