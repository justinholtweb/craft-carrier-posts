<?php

namespace justinholtweb\carrierposts\carriers;

use Craft;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrierposts\auth\PostaSlovenijeTrackingAuth;

/**
 * Pošta Slovenije — tracking, and (opt-in) Paketomat and post-office lookup. **No labels.**
 *
 * ## Why this carrier does not buy labels
 *
 * Pošta Slovenije's shipment API (eSpremnica "eOddaja", JSON spec of November 2019) is a data
 * submission, not a label service:
 *
 * 1. It never returns a tracking number. The merchant allocates barcodes from a range Pošta
 *    Slovenije assigns per product, as UPU S10 numbers with a mod-11 check digit.
 * 2. It never returns a label. The merchant must print one to Pošta Slovenije's own layout
 *    specification, which is issued with the contract and is not public. The only server PDF is
 *    the CN23 customs form.
 * 3. It is asynchronous: get a GUID, post the data, then poll a status 15–30 seconds later for
 *    per-row validation errors. A checkout or a bulk label run cannot wait on that.
 * 4. The values that decide what a shipment *is* — the `ParcelType` codes, the service code for
 *    cash on delivery, how a Paketomat is addressed — are issued per contract and unpublished.
 *
 * Carrier's contract is that a successful `createShipment()` returns a real tracking number and
 * label bytes a courier will accept. The S10 barcode format is public, but a label that passes
 * Pošta Slovenije's sorting is not something that can be built from public information, and a
 * plausible-looking PDF that is refused at the counter is worse than no label at all. So
 * `LABELS` (and with it cancel, reprint and COD) is deliberately not declared, and no barcode
 * allocator is shipped: without a label there is nothing for an allocated number to go on.
 * Merchants create shipments in Pošta Slovenije's own tools (eSpremnica / the ePortal), and
 * Carrier tracks those barcodes once they are attached to a shipment — which needs a core screen
 * for recording an externally created shipment that Carrier 5.0 does not have yet.
 *
 * ## What it does
 *
 * - **Tracking** through the Azure tracking API that replaced the WCF service on 2025-10-01:
 *   HTTP Basic (a GUID user and password) plus a "user token" exchanged for a JWT; then
 *   `parcels.select.barcode.queue`, at most ten barcodes per call, covering the last 60 days.
 * - **Points**, only when the merchant switches on the unofficial feed: the JSON that the
 *   posta.si website's own office finder calls. It is not an API contract, it has no
 *   credentials, and it may change or disappear without notice — which is why it defaults off
 *   and why this is search mode (by postcode or place name, live, cached briefly) rather than a
 *   daily sync of a list Pošta Slovenije never promised.
 *
 * Vendor traps:
 *
 * - The tracking token request's body is a bare JSON *string*, and so is the answer.
 * - The point feed's `Pin.GPSx` is the **latitude** and `GPSy` the longitude.
 * - Point ids: `"1101"` is a post office, `"SIP1701"` a PS Paketomat, `"2SIB…"` a fuel station.
 *
 * UNVERIFIED:
 *
 * - **The tracking response shape is not published**, and Pošta Slovenije says the field names
 *   changed from the old WCF service. This class reads the old service's names
 *   (`DogodkiPosiljke[]` with `DatumDogodka`, `Status`, `OpisStatusaStranka`, `PostaNaziv`,
 *   `VrnjenoPostaId`, and `CrtnaKoda` for the barcode), which is the best public evidence, and
 *   maps statuses by Slovenian keyword because the status-code list is not public either.
 * - The tracking JWT's lifetime, read from its `exp` claim with a 30-minute fallback.
 * - Whether the public tracking link works without the per-contract `guid` parameter.
 * - The point feed's postcode and city: it returns one address line, which is split on a
 *   trailing "1000 Ljubljana" when present.
 */
class PostaSlovenijeCarrier extends Carrier
{
    private const TRACKING_BASE = 'https://tracking.appservice.azuremb.posta.si/api';
    private const TOKEN_URL = 'https://oauth.appservice.azuremb.posta.si/api/oauth.token';
    private const POINT_FEED = 'https://www.posta.si/_vti_bin/PostaSI/PostOffices/PostOfficesService.svc/PostalOffice/';

    private const ZONE = 'Europe/Ljubljana';

    /**
     * Slovenian status wording → normalised status, first match wins, so the more specific
     * phrases come first. UNVERIFIED: keyword mapping, because codes are not published.
     */
    private const KEYWORDS = [
        'nevročljiv' => TrackingStatus::EXCEPTION,
        'vrnjen' => TrackingStatus::RETURNED,
        'vračilo' => TrackingStatus::RETURNED,
        'vročen' => TrackingStatus::DELIVERED,
        'čaka na prevzem' => TrackingStatus::READY_FOR_PICKUP,
        'paketomat' => TrackingStatus::READY_FOR_PICKUP,
        'obvestilo' => TrackingStatus::READY_FOR_PICKUP,
        'v dostavi' => TrackingStatus::OUT_FOR_DELIVERY,
        'prispel' => TrackingStatus::IN_TRANSIT,
        'v obdelavi' => TrackingStatus::IN_TRANSIT,
        'sprejem' => TrackingStatus::IN_TRANSIT,
        'podatki' => TrackingStatus::PRE_TRANSIT,
    ];

    public static function handle(): string
    {
        return 'posta-si';
    }

    public static function displayName(): string
    {
        return 'Pošta Slovenije';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Europe');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'Slovenia’s national post: tracking for parcels you send with Pošta Slovenije’s own tools. Labels are not available through an API.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://www.posta.si/api-za-sledenje-faq';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::TRACKING)
            ->points(Capabilities::POINTS_SEARCH, PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_POST_OFFICE, PickupPoint::TYPE_SHOP)
            ->originCountries('SI')
            ->trackingBatch(10);
    }

    public static function settingsFields(): array
    {
        return [
            Field::heading('Tracking API', 'Three values from your Pošta Slovenije contract manager for the tracking API (eSledenje). They are separate from your eSpremnica credentials.'),
            Field::secret('trackingUser', 'Tracking user', [
                'required' => true,
                'instructions' => 'A GUID. Store it in an environment variable.',
            ]),
            Field::secret('trackingPassword', 'Tracking password', [
                'required' => true,
                'instructions' => 'A GUID. Store it in an environment variable.',
            ]),
            Field::secret('trackingUserToken', 'User token', [
                'required' => true,
                'instructions' => 'The long user token Pošta Slovenije issued, exactly as given, without quotes.',
            ]),
            Field::heading('Pickup points'),
            Field::boolean('usePointFeed', 'Use the unofficial posta.si point finder', [
                'default' => false,
                'instructions' => 'Pošta Slovenije has no public pickup-point API. This uses the JSON behind the office finder on posta.si, which is not a supported interface and may change without notice. Leave it off unless you offer Paketomat delivery and accept that risk.',
            ]),
        ];
    }

    public static function services(): array
    {
        return [];
    }

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://sledenje.posta.si/Default.aspx?tn=' . rawurlencode($trackingNumber) . '&lang=si';
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    protected function buildAuth(): ?AuthInterface
    {
        return new PostaSlovenijeTrackingAuth(self::TOKEN_URL);
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(self::TRACKING_BASE)
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    protected function probe(): HealthResult
    {
        /** @var PostaSlovenijeTrackingAuth $auth */
        $auth = $this->auth();

        if ($auth->accessToken() === null) {
            return HealthResult::fail(
                Craft::t('carrier', 'Pošta Slovenije did not accept the tracking credentials.'),
                [Craft::t('carrier', 'The user and password are GUIDs; the user token is the long value issued with them. All three come from your contract manager.')],
            );
        }

        $response = $this->transport()->get('easistecho');

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage());
        }

        return HealthResult::pass(Craft::t('carrier', 'Connected to Pošta Slovenije tracking.'), [
            'Point finder' => $this->boolSetting('usePointFeed') ? 'Unofficial feed on' : 'Off',
        ]);
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $numbers = array_slice(array_values(array_filter(array_map(
            static fn($n) => strtoupper(trim((string)$n)),
            $trackingNumbers,
        ))), 0, 10);

        if ($numbers === []) {
            return [];
        }

        $response = $this->transport()->post('parcels.select.barcode.queue', $numbers);

        if (!$response->ok()) {
            return [];
        }

        $out = [];

        foreach (self::parcels($response) as $i => $parcel) {
            $number = strtoupper(trim((string)($parcel['CrtnaKoda'] ?? $parcel['Barcode'] ?? $parcel['barcode'] ?? '')));

            // One result per barcode in request order is the likeliest shape; fall back to
            // position only when the parcel does not name itself.
            if ($number === '') {
                $number = $numbers[$i] ?? '';
            }

            if ($number === '') {
                continue;
            }

            $events = $parcel['DogodkiPosiljke'] ?? $parcel['Events'] ?? $parcel['events'] ?? null;

            if (!is_array($events) || $events === []) {
                $out[] = new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
                continue;
            }

            $out[] = $this->trackingInfo($number, $events);
        }

        return $out;
    }

    /**
     * The list of parcels in a tracking answer: a bare list, or a list under one key.
     */
    private static function parcels(Response $response): array
    {
        $json = $response->json_();

        if ($json === []) {
            return [];
        }

        if (array_is_list($json)) {
            return array_values(array_filter($json, 'is_array'));
        }

        foreach (['Posiljke', 'Parcels', 'parcels', 'Value', 'value'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return array_values(array_filter($json[$key], 'is_array'));
            }
        }

        // A single parcel object.
        return isset($json['DogodkiPosiljke']) || isset($json['CrtnaKoda']) ? [$json] : [];
    }

    private function trackingInfo(string $number, array $rows): TrackingInfo
    {
        $events = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $text = trim((string)($row['OpisStatusaStranka'] ?? $row['PrevodStatusa'] ?? $row['Opis'] ?? ''));
            $code = trim((string)($row['Status'] ?? ''));
            $status = self::keywordStatus($text . ' ' . $code);

            if (!empty($row['VrnjenoPostaId'])) {
                $status = TrackingStatus::RETURNED;
            }

            $events[] = new TrackingEvent([
                'occurredAt' => self::parseDate((string)($row['DatumDogodka'] ?? $row['Datum'] ?? ''), self::ZONE),
                'status' => $status,
                'code' => $code,
                'description' => $text !== '' ? $text : $code,
                'location' => trim((string)($row['PostaNaziv'] ?? '')),
            ]);
        }

        usort($events, static fn(TrackingEvent $a, TrackingEvent $b) => ($a->occurredAt?->getTimestamp() ?? 0) <=> ($b->occurredAt?->getTimestamp() ?? 0));
        $last = end($events) ?: null;

        return new TrackingInfo([
            'trackingNumber' => $number,
            'status' => $last?->status ?? TrackingStatus::UNKNOWN,
            'statusText' => $last?->description ?? '',
            'deliveredAt' => $last?->status === TrackingStatus::DELIVERED ? $last->occurredAt : null,
            'events' => $events,
        ]);
    }

    private static function keywordStatus(string $text): string
    {
        $t = mb_strtolower($text);

        foreach (self::KEYWORDS as $needle => $status) {
            if (str_contains($t, $needle)) {
                return $status;
            }
        }

        return TrackingStatus::IN_TRANSIT;
    }

    // =========================================================================
    // Points
    // =========================================================================

    public function pickupPoints(PointQuery $query): PointPage
    {
        if (!$this->boolSetting('usePointFeed')) {
            return PointPage::failed(Craft::t('carrier', 'Pickup points for Pošta Slovenije are switched off. Turn on the unofficial point finder in the connection settings to use them.'));
        }

        if ($query->countryCode !== '' && strtoupper($query->countryCode) !== 'SI') {
            return PointPage::of([]);
        }

        $search = trim($query->postcode !== '' ? $query->postcode : $query->city);

        if ($search === '') {
            return PointPage::of([]);
        }

        /** @var PostaSlovenijeTrackingAuth $auth */
        $auth = $this->auth();

        // A public website: it must not be handed the tracking JWT.
        $auth->pause(true);

        try {
            $response = $this->transport()->get(self::POINT_FEED . rawurlencode($search));
        } finally {
            $auth->pause(false);
        }

        if (!$response->ok()) {
            return PointPage::failed($response->errorMessage());
        }

        $points = [];

        foreach ($response->json_() as $row) {
            if (!is_array($row) || trim((string)($row['PostalId'] ?? '')) === '') {
                continue;
            }

            $type = match ((int)($row['VrstaEnote'] ?? 0)) {
                2 => PickupPoint::TYPE_LOCKER,
                1 => PickupPoint::TYPE_SHOP,
                default => PickupPoint::TYPE_POST_OFFICE,
            };

            if ($query->types !== [] && !in_array($type, $query->types, true)) {
                continue;
            }

            $address = trim((string)($row['PostalAddress'] ?? ''));
            [$street, $postcode, $city] = self::splitAddress($address);
            $pin = is_array($row['Pin'] ?? null) ? $row['Pin'] : [];

            $points[] = new PickupPoint([
                'id' => trim((string)$row['PostalId']),
                'type' => $type,
                'name' => trim((string)($row['PostalClassNumAndTitle'] ?? '')) ?: trim((string)$row['PostalId']),
                'street' => $street,
                'city' => $city,
                'postcode' => $postcode !== '' ? $postcode : (ctype_digit($search) ? $search : ''),
                'countryCode' => 'SI',
                'latitude' => is_numeric($pin['GPSx'] ?? null) ? (float)$pin['GPSx'] : null,
                'longitude' => is_numeric($pin['GPSy'] ?? null) ? (float)$pin['GPSy'] : null,
                'hours' => self::hours($row['FormatWorkingDays'] ?? []),
                'extra' => ['source' => 'posta.si website (unofficial)'],
            ]);

            if (count($points) >= max(1, $query->limit)) {
                break;
            }
        }

        return PointPage::of($points);
    }

    /** "Slovenska cesta 32, 1000 Ljubljana" → [street, postcode, city]. */
    private static function splitAddress(string $address): array
    {
        if (preg_match('/^(.*?),?\s+(\d{4})\s+(.+)$/u', $address, $m)) {
            return [trim($m[1], ' ,'), $m[2], trim($m[3])];
        }

        return [$address, '', ''];
    }

    private static function hours(mixed $days): string
    {
        if (!is_array($days)) {
            return is_string($days) ? $days : '';
        }

        $parts = [];

        foreach ($days as $day) {
            if (is_string($day)) {
                $parts[] = trim($day);
            } elseif (is_array($day)) {
                $parts[] = trim(implode(' ', array_filter(array_map(
                    static fn($v) => is_scalar($v) ? (string)$v : '',
                    $day,
                ))));
            }
        }

        return implode('; ', array_filter($parts));
    }
}
