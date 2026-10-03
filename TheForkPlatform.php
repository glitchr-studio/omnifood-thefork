<?php

namespace Omnifood\TheFork;

use Omnifood\Auth\RefreshableInterface;
use Omnifood\Channel;
use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Exception\NotSupportedException;
use Omnifood\Model\Capabilities;
use Omnifood\Model\Guest;
use Omnifood\Model\Notification;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\Reservation;
use Omnifood\Model\ReservationStatus;
use Omnifood\Model\Slot;
use Omnifood\Model\Token;
use Omnifood\NotifiableInterface;
use Omnifood\ReservationsInterface;

/**
 * A restaurant's reservations at TheFork, through the B2B API
 * (https://docs.thefork.io/B2B-API/introduction, reference
 * https://api.thefork.io/manager/openapi.json).
 *
 * - reservations() lists the ids booked from one day to another (by meal
 *   date) and reads each, and its customer when with_customers is on: the
 *   list itself carries ids only.
 * - createReservation() books a table (normal stock, or the offer in
 *   raw['offerUuid']); updateReservation() changes the date, the party
 *   size and the staff note - nothing else is writable;
 *   cancelReservation() cancels it, as the restaurant (TheFork takes no
 *   reason).
 * - The service's statuses (arrived, seated, left, no-show) are read
 *   (status, mealStatus) but TheFork publishes no call to set them:
 *   setStatus() only cancels.
 * - pushAvailability() opens or closes time slots for online booking
 *   (TheFork's override: a range, open or closed - not a number of covers).
 * - Its webhooks carry an entity and an event, not the reservation:
 *   notify() says which, reservation() reads it.
 *
 * Tables, deposits and card imprints on a reservation are not in the
 * public API.
 */
final class TheForkPlatform implements ReservationsInterface, NotifiableInterface, RefreshableInterface
{
    /** reservations()' page size; TheFork takes up to 10000 */
    public const PAGE = 100;

    public function __construct(
        private readonly Api $api,
        private readonly ?string $restaurantId = null,
        private readonly ?string $webhookToken = null,
        private readonly string $locale = 'fr_FR',
        private readonly string $civility = 'mx',
        private readonly bool $withCustomers = true,
    ) {
    }

    public function getName(): string
    {
        return 'thefork';
    }

    public function getChannel(): Channel
    {
        return Channel::THEFORK;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(deposits: false, availabilityPush: true);
    }

    public function reservations(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ids = [];
        $page = 1;
        do {
            $answer = $this->api->request('GET', '/v1/reservations', [
                'restaurantUuid' => $this->restaurant(),
                // Days, inclusive: the times are kept by the filter below.
                'startDate' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
                'endDate' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
                'filterBy' => 'mealDate',
                'limit' => self::PAGE,
                'page' => $page,
            ]);
            $ids = array_merge($ids, array_map('strval', (array) ($answer['data'] ?? [])));
            $total = (int) ($answer['totalCount'] ?? 0);
        } while ($page++ * self::PAGE < $total && !empty($answer['data']));

        $reservations = array_values(array_filter(
            array_map($this->reservation(...), array_values(array_unique($ids))),
            static fn (Reservation $r) => $r->date >= $from && $r->date <= $to,
        ));
        usort($reservations, static fn (Reservation $a, Reservation $b) => $a->date <=> $b->date);

        return $reservations;
    }

    public function reservation(string $ref): Reservation
    {
        $data = $this->api->request('GET', '/v1/reservations/'.rawurlencode($ref));
        $customer = null;
        if ($this->withCustomers && !empty($data['customerUuid'])) {
            $customer = $this->api->request('GET', '/v1/customers/'.rawurlencode((string) $data['customerUuid']));
        }

        return $this->toReservation($data, $customer);
    }

    /**
     * Booked on normal stock, or on the offer in $reservation->raw['offerUuid'].
     * TheFork requires the guest's e-mail, first and last names, a locale
     * and a civility (raw['civility'], else the configured one); the phone
     * in E.164. raw['restaurantNote'] is the staff's note.
     */
    public function createReservation(Reservation $reservation): Reservation
    {
        $guest = $reservation->guest;
        $data = $this->api->request('POST', '/v1/restaurants/'.rawurlencode($this->restaurant()).'/reservations', [], array_filter([
            'mealDate' => $reservation->date->format(\DateTimeInterface::ATOM),
            'partySize' => $reservation->covers,
            'offerUuid' => $reservation->raw['offerUuid'] ?? null,
            'customerNote' => $reservation->notes,
            'restaurantNote' => $reservation->raw['restaurantNote'] ?? null,
            'customer' => array_filter([
                'email' => $guest->email,
                'firstName' => $guest->firstName,
                'lastName' => $guest->lastName,
                'phone' => $guest->phone ? self::e164($guest->phone) : null,
                'locale' => self::locale($guest->locale ?? $this->locale),
                'civility' => $reservation->raw['civility'] ?? $this->civility,
            ], static fn ($v) => null !== $v && '' !== $v),
        ], static fn ($v) => null !== $v));

        return $this->toReservation($data, \is_array($data['customer'] ?? null) ? $data['customer'] : null);
    }

    /** The date and the party size; the staff's note when raw['restaurantNote'] is there (null clears it). */
    public function updateReservation(Reservation $reservation): Reservation
    {
        $body = ['mealDate' => $reservation->date->format(\DateTimeInterface::ATOM), 'partySize' => $reservation->covers];
        if (\array_key_exists('restaurantNote', $reservation->raw)) {
            $body['restaurantNote'] = $reservation->raw['restaurantNote'];
        }
        $data = $this->api->request('PATCH', '/v1/reservations/'.rawurlencode($reservation->reference ?? throw new \InvalidArgumentException('A reservation to update has its reference.')), [], $body);

        return $this->toReservation($data, \is_array($data['customer'] ?? null) ? $data['customer'] : null);
    }

    /** Cancelled as the restaurant; TheFork records no reason given ($reason is not sent). */
    public function cancelReservation(string $ref, ?string $reason = null): void
    {
        $this->api->request('PATCH', '/v1/reservations/'.rawurlencode($ref).'/cancel');
    }

    public function setStatus(string $ref, ReservationStatus $status): void
    {
        if (ReservationStatus::CANCELLED === $status) {
            $this->cancelReservation($ref);

            return;
        }

        throw NotSupportedException::operation($this->getName(), \sprintf('set a reservation %s (TheFork\'s public API reads the service\'s statuses but publishes no call to set them)', $status->value));
    }

    /**
     * Each slot opened (covers > 0) or closed (0) to online booking, from
     * its start to its end (the slot starting at the end included; no end:
     * its start alone), in the restaurant's local time.
     */
    public function pushAvailability(array $slots): void
    {
        foreach ($slots as $slot) {
            if (!$slot instanceof Slot) {
                throw new \InvalidArgumentException('A list of Slot is expected.');
            }
            $this->api->request('PUT', '/v1/restaurants/'.rawurlencode($this->restaurant()).'/availabilities/override', [], array_filter([
                'date' => $slot->start->format('Y-m-d'),
                'startTime' => $slot->start->format('H:i'),
                'endTime' => $slot->end?->format('H:i'),
                'isOpen' => $slot->covers > 0,
            ], static fn ($v) => null !== $v));
        }
    }

    /**
     * A webhook from TheFork: {"entityType", "eventType", "uuid",
     * "groupUuid", "restaurantUuid"}. TheFork authenticates it by the token
     * the site put in the URL it gave (…?token=…) and nothing else: the
     * controller passes that query parameter among $headers as "token"
     * (`$request->headers->all() + ['token' => $request->query->get('token')]`).
     *
     * The notification carries no event id (Notification::$id is null) and
     * no data: a reservationCreated or reservationUpdated is read again
     * with reservation($notification->reference). The endpoint answers 200
     * {"data": {}} within a few seconds.
     */
    public function notify(string $body, array $headers): Notification
    {
        if (!$this->webhookToken) {
            throw new InvalidConfigException('The "thefork" platform needs: webhook_token, to check its webhooks.');
        }
        $headers = array_change_key_case(array_map(static fn ($v) => \is_array($v) ? (string) reset($v) : (string) $v, $headers));
        if (!hash_equals($this->webhookToken, $headers['token'] ?? '')) {
            throw new InvalidSignatureException($this->getName(), 'The webhook\'s token is not the one given to TheFork.');
        }
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new InvalidSignatureException($this->getName(), 'The body is not JSON.');
        }
        $event = (string) ($data['eventType'] ?? 'unknown');

        return new Notification(
            Channel::THEFORK,
            $event,
            'reservation' === ($data['entityType'] ?? null) ? NotificationSubject::RESERVATION : NotificationSubject::OTHER,
            isset($data['uuid']) ? (string) $data['uuid'] : null,
            null,
            store: isset($data['restaurantUuid']) ? (string) $data['restaurantUuid'] : null,
            raw: $data,
        );
    }

    public function token(): ?Token
    {
        return $this->api->heldToken();
    }

    /** A new token (about 2 h 23), for the site to keep and give back as access_token / token_expires_at. */
    public function refresh(): Token
    {
        return $this->api->refresh();
    }

    /**
     * A reservation as TheFork answers it, its customer read or nested.
     *
     * @param array<string, mixed>      $r
     * @param array<string, mixed>|null $c
     */
    public function toReservation(array $r, ?array $c = null): Reservation
    {
        $allergies = array_values(array_filter((array) ($c['allergiesAndIntolerances'] ?? [])));

        return new Reservation(
            new \DateTimeImmutable((string) ($r['mealDate'] ?? 'now')),
            (int) ($r['partySize'] ?? 0),
            new Guest(
                $c['firstName'] ?? null,
                $c['lastName'] ?? null,
                $c['email'] ?? null,
                $c['phone'] ?? null,
                $c['locale'] ?? null,
                isset($c['customerUuid']) ? (string) $c['customerUuid'] : (isset($r['customerUuid']) ? (string) $r['customerUuid'] : null),
            ),
            self::status((string) ($r['status'] ?? ''), $r['mealStatus'] ?? null),
            (string) ($r['reservationUuid'] ?? ''),
            Channel::THEFORK,
            $r['customerNote'] ?? null,
            $allergies ? implode(', ', $allergies) : null,
            null,
            null,
            null,
            $r['reservationChannel'] ?? null,
            isset($r['createdAt']) ? new \DateTimeImmutable((string) $r['createdAt']) : null,
            isset($r['restaurantUuid']) ? (string) $r['restaurantUuid'] : null,
            $r,
        );
    }

    /** status and, once confirmed, mealStatus: the service as it goes. */
    public static function status(string $status, ?string $mealStatus = null): ReservationStatus
    {
        return match ($status) {
            'RECORDED' => match ($mealStatus) {
                'PARTIALLY_ARRIVED', 'ARRIVED' => ReservationStatus::ARRIVED,
                'SEATED', 'BILL' => ReservationStatus::SEATED,
                'LEFT' => ReservationStatus::FINISHED,
                default => ReservationStatus::CONFIRMED,
            },
            'REQUESTED' => ReservationStatus::REQUESTED,
            'CANCELED' => ReservationStatus::CANCELLED,
            'NO_SHOW' => ReservationStatus::NO_SHOW,
            'REFUSED' => ReservationStatus::REFUSED,
            default => ReservationStatus::UNKNOWN,
        };
    }

    private function restaurant(): string
    {
        return $this->restaurantId ?: throw new InvalidConfigException('The "thefork" platform needs: restaurant_id.');
    }

    /** "+33 6 12 34 56 78" as TheFork wants it: "+33612345678". */
    private static function e164(string $phone): string
    {
        return '+'.ltrim((string) preg_replace('/[^\d+]/', '', $phone), '+');
    }

    /** "fr", "fr_FR", "fr-fr" as TheFork wants it: ll or ll_CC. */
    private static function locale(string $locale): string
    {
        $parts = preg_split('/[-_]/', $locale) ?: [$locale];

        return strtolower($parts[0]).(isset($parts[1]) ? '_'.strtoupper($parts[1]) : '');
    }
}
