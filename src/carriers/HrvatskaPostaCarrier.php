<?php

namespace justinholtweb\carrierposts\carriers;

use Craft;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\LabelResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrierposts\auth\HrvatskaPostaAuth;

/**
 * Hrvatska pošta — "HP Shipping Service API" (DXWebAPI), product name Paket 24.
 *
 * Written against HP's own v1 documentation (28.11.2022, the English and Croatian .docx on
 * posta.hr, identical in content). Confidence is high for every call this class makes.
 *
 * Vendor traps, in the order they bite:
 *
 * - **Two ports.** Sign-in is on 9000, everything else on 9020. Shared hosting very often blocks
 *   outbound traffic to non-443 ports; the symptom is a connect timeout, not an HTTP error, and
 *   `probe()` says so in words.
 * - **GET with a JSON body.** Labels, tracking and the point list are `GET` requests whose
 *   parameters are a JSON body (HP's own PHP sample uses `CURLOPT_CUSTOMREQUEST => 'GET'` with
 *   `CURLOPT_POSTFIELDS`). Carrier's transport sends a body on any method; a proxy in between
 *   that strips GET bodies will make these calls fail with a validation error.
 * - **snake_case in, PascalCase out** — except `Packages[].barcode`, which stays lowercase.
 * - **Errors are per order** (`ResponseStatus: 1` + `ErrorCode` + `ErrorMessage`) inside an
 *   HTTP 200. No error-code catalogue is published.
 * - **Field limits are tight**: name 50, street 50, city 25, postcode 5, phone 25 and — the one
 *   that hurts — **e-mail 25**. Longer values are truncated; an e-mail over 25 characters is
 *   *dropped* (a truncated address is a wrong address) and the shipment says so in a warning.
 * - **House number is its own field** (`hnum` + `hnum_suffix`; the suffix is "required", so an
 *   empty string is sent). "242a" becomes `242` + `a`.
 * - **Cancellation is by client reference**, not barcode. HP requires that reference to be
 *   unique per order, so this class appends a short time stamp to the order reference: a
 *   second shipment for the same order (a split, a re-ship after a void) would otherwise be
 *   refused as a duplicate. The reference used is kept in `data['clientReference']`.
 * - **Paketomat** delivery is `delivery_type: 3` + the locker `code` in
 *   `recipient_delivery_center` + a compartment size `X`/`S`/`M`/`L`. The size is chosen from the
 *   parcel's dimensions against `LOCKER_SIZES`; override it per shipment with the request option
 *   `parcelSize`, or set the default used for parcels without dimensions. Locker and post-office
 *   codes are sent exactly as given, never cut to the documented five characters: a shortened
 *   code would name a different point, and a refusal is the better outcome.
 * - **COD** is additional service 9 plus `payment_value`. The docs name no currency; Croatia has
 *   used the euro since 2023, so anything but EUR is refused here rather than mis-collected.
 * - **Labels are PDF only**, base64. No ZPL is documented.
 * - **Tracking times have no zone**; they are read as Europe/Zagreb.
 * - **Point list coordinate keys are misspelled and differ per list**: post offices have
 *   `geoLatitude` / `getLongitude`, Paketomats `geoLat` / `getLng`. Both are read, and the
 *   correctly-spelled forms too in case HP ever fixes them.
 *
 * UNVERIFIED:
 *
 * - The size of the default (non-A4) label page. Presumed 10×15 / A6, so it is labelled
 *   `pdf-a6`.
 * - The Paketomat compartment dimensions in `LOCKER_SIZES` (HP publishes them on
 *   posta.hr/dimenzije-pretinaca/9279; the values here are typical locker sizes, not HP's).
 * - That a post office's `recipient_delivery_center` is its five-digit postcode. The docs imply
 *   it (post offices have no `code` field) but never say so.
 * - Until when a cancellation is accepted. Presumably until the parcel is collected.
 * - HP's public tracking page URL, so `trackingUrl()` returns null.
 * - "Ready in a Paketomat" has no scan of its own; `ODG` is read as ready-for-pickup when the
 *   parcel's history shows it was announced to a locker or post office, as an exception otherwise.
 *
 * No rates: HP has no rate endpoint, so methods use a price table from the contract.
 */
class HrvatskaPostaCarrier extends Carrier
{
    private const HOST_PRODUCTION = 'dxwebapi.posta.hr';
    private const HOST_TEST = 'dxwebapit.posta.hr';

    private const ZONE = 'Europe/Zagreb';

    /**
     * Paketomat compartments, centimetres, smallest dimension first. UNVERIFIED — see the class
     * docblock. A parcel goes in the first compartment its sorted dimensions fit.
     */
    private const LOCKER_SIZES = [
        'X' => [5, 35, 60],
        'S' => [9, 38, 64],
        'M' => [19, 38, 64],
        'L' => [39, 38, 64],
    ];

    /** Scan code => normalised status. Meanings from HP's documentation; the mapping is ours. */
    private const SCANS = [
        'NOV' => TrackingStatus::PRE_TRANSIT,
        'ZAP' => TrackingStatus::PRE_TRANSIT,
        'NPR' => TrackingStatus::PRE_TRANSIT,
        'PRI' => TrackingStatus::IN_TRANSIT,
        'ZPR' => TrackingStatus::IN_TRANSIT,
        'OTP' => TrackingStatus::IN_TRANSIT,
        'ZOT' => TrackingStatus::IN_TRANSIT,
        'UKO' => TrackingStatus::IN_TRANSIT,
        'PRE' => TrackingStatus::IN_TRANSIT,
        'ZAD' => TrackingStatus::OUT_FOR_DELIVERY,
        'IOD' => TrackingStatus::DELIVERED,
        'IND' => TrackingStatus::EXCEPTION,
        'ODG' => TrackingStatus::EXCEPTION,
        'POV' => TrackingStatus::RETURNED,
        'IPP' => TrackingStatus::RETURNED,
    ];

    /** `CommentNPA` codes on a failed delivery (`IND`) that change what it means. */
    private const IND_READY = ['7010004', '7010017'];
    private const IND_RETURNED = ['7010003'];

    /** `CommentNPA` codes showing a parcel was routed to a locker or a counter. */
    private const HELD_AT_POINT = ['5006001', '5006002', '7010004', '7010017'];

    public static function handle(): string
    {
        return 'posta-hr';
    }

    public static function displayName(): string
    {
        return 'Hrvatska pošta';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Europe');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'Croatia’s national post: Paket 24 to the door, a post office or a Paketomat, with cash on delivery.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://www.posta.hr/paket24';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(
                Feature::LABELS, Feature::CANCEL, Feature::REPRINT, Feature::TRACKING,
                Feature::COD, Feature::MULTI_PIECE,
            )
            ->points(Capabilities::POINTS_LIST, PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_POST_OFFICE)
            ->labelFormats(LabelFormat::PDF_A6, LabelFormat::PDF_A4)
            ->originCountries('HR')
            ->trackingBatch(20)
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::environment([
                'instructions' => 'Test uses dxwebapit.posta.hr. Hrvatska pošta issues separate credentials for each environment.',
            ]),
            Field::text('username', 'Username', [
                'required' => true,
                'instructions' => 'The API username from your Hrvatska pošta contract manager.',
            ]),
            Field::secret('password', 'Password', ['required' => true]),
            Field::heading('Hand-over', 'How parcels reach Hrvatska pošta. Your server must be able to reach ports 9000 and 9020 on posta.hr; many shared hosts block them.'),
            Field::select('pickupType', 'Parcels are handed over', [
                '1' => 'Collected from the ship-from address',
                '2' => 'At a post office',
                '3' => 'At a Paketomat',
            ]),
            Field::text('senderPickupCenter', 'Hand-over post office or Paketomat code', [
                'instructions' => 'Required when parcels are handed over at a post office or Paketomat.',
            ]),
            Field::select('defaultLockerSize', 'Paketomat compartment for parcels without dimensions', [
                'M' => 'M',
                'S' => 'S',
                'X' => 'X',
                'L' => 'L',
            ], [
                'instructions' => 'Parcels with dimensions get the smallest compartment they fit.',
            ]),
        ];
    }

    public static function services(): array
    {
        return [
            '26' => ['name' => 'Paket 24 D+1', 'international' => false, 'points' => false],
            '29' => ['name' => 'Paket 24 D+2', 'international' => false, 'points' => false],
            '32' => ['name' => 'Paket 24 D+3', 'international' => false, 'points' => false],
            '38' => ['name' => 'Paket 24 D+4', 'international' => false, 'points' => false],
            '26-PAK' => ['name' => 'Paket 24 D+1 to a Paketomat', 'international' => false, 'points' => true, 'pointTypes' => [PickupPoint::TYPE_LOCKER]],
            '26-PU' => ['name' => 'Paket 24 D+1 to a post office', 'international' => false, 'points' => true, 'pointTypes' => [PickupPoint::TYPE_POST_OFFICE]],
        ];
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    private function host(): string
    {
        return $this->isSandbox() ? self::HOST_TEST : self::HOST_PRODUCTION;
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new HrvatskaPostaAuth('https://' . $this->host() . ':9000/api/authentication/client_auth');
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri('https://' . $this->host() . ':9020/api')
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    protected function probe(): HealthResult
    {
        /** @var HrvatskaPostaAuth $auth */
        $auth = $this->auth();

        if ($auth->accessToken() === null) {
            return HealthResult::fail(
                Craft::t('carrier', 'Hrvatska pošta did not accept the username and password.'),
                [
                    Craft::t('carrier', 'Check that the credentials match the environment: test and production credentials are different.'),
                    Craft::t('carrier', 'If this times out, your host is probably blocking outbound port 9000. Ask them to allow dxwebapi.posta.hr on ports 9000 and 9020.'),
                ],
            );
        }

        // Signing in only proves port 9000. A tiny point search proves 9020 is reachable too.
        $response = $this->transport()->request('GET', 'delivery_point/get_parcel_delivery_point', [
            'json' => ['facilityType' => 'PU', 'nextWeek' => 0, 'searchText' => '10000'],
        ]);

        if ($response->status === 0) {
            return HealthResult::fail(
                Craft::t('carrier', 'Signed in, but port 9020 could not be reached.'),
                [Craft::t('carrier', 'Your host is blocking outbound port 9020. Ask them to allow dxwebapi.posta.hr on ports 9000 and 9020.')],
            );
        }

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage());
        }

        return HealthResult::pass(Craft::t('carrier', 'Connected to Hrvatska pošta.'), [
            'Environment' => $this->isSandbox() ? 'Test' : 'Production',
        ]);
    }

    // =========================================================================
    // Labels
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $from = $request->shipFrom;
        $to = $request->shipTo;

        if ($from === null || $to === null || $request->parcels === []) {
            return ShipmentResult::rejected(Craft::t('carrier', 'A shipment needs a sender, a recipient and at least one parcel.'));
        }

        [$service, $deliveryType] = $this->splitService($request->serviceCode);
        $warnings = [];

        if ($deliveryType !== 1 && $request->pointId === '') {
            return ShipmentResult::rejected(Craft::t('carrier', 'This Hrvatska pošta service delivers to a post office or Paketomat, and none was chosen.'));
        }

        $order = [
            'client_reference_number' => $this->clientReference($request),
            'service' => $service,
            'payed_by' => 1,
            'delivery_type' => $deliveryType,
            'pickup_type' => (int)($this->setting('pickupType', '1') ?: 1),
            'sender' => $this->party('sender', $from, $warnings),
            'recipient' => $this->party('recipient', $to, $warnings),
            'additional_services' => [],
            'packages' => [],
        ];

        if ($order['pickup_type'] !== 1) {
            // Codes are sent verbatim: a code cut to fit would name a different counter.
            $order['sender']['sender_pickup_center'] = trim((string)$this->setting('senderPickupCenter', ''));
        }

        if ($deliveryType !== 1) {
            $order['recipient']['recipient_delivery_center'] = trim($request->pointId);
        }

        if ($deliveryType === 3) {
            $size = $this->lockerSize($request);

            if ($size === null) {
                return ShipmentResult::rejected(Craft::t('carrier', 'This parcel is too big for a Paketomat compartment.'));
            }

            $order['parcel_size'] = $size;
        }

        if ($request->hasCod()) {
            $currency = strtoupper($request->codCurrency ?: $request->currency);

            if ($currency !== '' && $currency !== 'EUR') {
                return ShipmentResult::rejected(Craft::t('carrier', 'Hrvatska pošta collects cash on delivery in euros only, not {currency}.', ['currency' => $currency]));
            }

            $order['additional_services'][] = ['additional_service_id' => 9];
            $order['payment_value'] = round($request->codAmount, 2);
        }

        if ($request->reference !== '') {
            $order['reference_field_B'] = mb_substr($request->reference, 0, 100);
        }

        foreach ($request->parcels as $i => $parcel) {
            $order['packages'][] = [
                'barcode' => '',
                'barcode_type' => 1,
                'barcode_client' => mb_substr($order['client_reference_number'] . '-' . ($i + 1), 0, 500),
                'weight' => Units::gramsToKg(max(1, $parcel->weightG), 3),
            ];
        }

        $transport = $this->transport()->setSafeRetriesOnly(true);

        try {
            $response = $transport->post('shipment/create_shipment_orders', [
                'parcels' => [$order],
                'return_address_label' => true,
            ]);
        } finally {
            $transport->setSafeRetriesOnly(false);
        }

        if (!$response->ok()) {
            return ShipmentResult::fromResponse($response);
        }

        $result = $response->at('ShipmentOrdersList.0');

        if (!is_array($result)) {
            // A 200 with nothing recognisable in it, after the order was sent: HP may have
            // created it. Park it for a human rather than letting a retry buy it twice.
            return ShipmentResult::uncertain(Craft::t('carrier', 'Hrvatska pošta answered without a shipment. Check its portal before trying again.'));
        }

        if ((int)($result['ResponseStatus'] ?? 1) !== 0) {
            return ShipmentResult::rejected(self::itemError($result));
        }

        $numbers = [];

        foreach ((array)($result['Packages'] ?? []) as $package) {
            $barcode = trim((string)($package['barcode'] ?? ''));

            if ($barcode !== '') {
                $numbers[] = $barcode;
            }
        }

        if (count($numbers) !== count($request->parcels)) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'Hrvatska pošta accepted the order but returned {got} barcodes for {want} parcels. Check its portal.', [
                'got' => count($numbers),
                'want' => count($request->parcels),
            ]));
        }

        $clientReference = (string)($result['ClientReferenceNumber'] ?? $order['client_reference_number']);
        $format = $this->labelFormatFor($request);
        $pdf = base64_decode((string)$response->at('ShipmentsLabel', ''), true) ?: '';
        $labelFormat = LabelFormat::PDF_A6;

        // The create call only returns the default single-label page; A4 four-up is a reprint.
        if ($format === LabelFormat::PDF_A4) {
            $a4 = $this->fetchLabels(['barcodes' => array_map(static fn($n) => ['barcode' => $n], $numbers)], true);

            if ($a4 !== null) {
                $pdf = $a4;
                $labelFormat = LabelFormat::PDF_A4;
            }
        }

        if (!LabelFormat::bytesMatch(LabelFormat::PDF_A6, $pdf)) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'Hrvatska pošta created {number} but returned no printable label. Reprint it from the shipment.', [
                'number' => $numbers[0],
            ]));
        }

        $shipment = ShipmentResult::ok($clientReference, $numbers, [
            new Label([
                'trackingNumber' => $numbers[0],
                'format' => $labelFormat,
                'kind' => $request->isReturn ? 'return' : 'label',
                'data' => $pdf,
            ]),
        ], [
            'clientReference' => $clientReference,
            'deliveryType' => $deliveryType,
        ]);

        $shipment->warnings = $warnings;

        return $shipment;
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $reference = (string)($shipment->data['clientReference'] ?? $shipment->shipmentId);

        if ($reference === '') {
            return ActionResult::failed(Craft::t('carrier', 'This shipment has no Hrvatska pošta client reference to cancel by.'));
        }

        $response = $this->transport()->post('shipment/cancel_shipment_orders', [
            'parcels' => [['client_reference_number' => $reference]],
        ]);

        if (!$response->ok()) {
            return ActionResult::failed($response->errorMessage());
        }

        // A bare array, one entry per reference.
        $item = $response->json_()[0] ?? null;

        if (!is_array($item)) {
            return ActionResult::failed(Craft::t('carrier', 'Hrvatska pošta did not confirm the cancellation.'));
        }

        if ((int)($item['ResponseStatus'] ?? 1) !== 0) {
            return ActionResult::failed(self::itemError($item));
        }

        return ActionResult::ok($reference);
    }

    public function printLabels(array $shipments, string $format): LabelResult
    {
        $barcodes = [];

        foreach ($shipments as $shipment) {
            foreach ($shipment->trackingNumbers as $number) {
                $barcodes[] = ['barcode' => (string)$number];
            }
        }

        if ($barcodes === []) {
            return LabelResult::failed(Craft::t('carrier', 'Nothing to print.'));
        }

        $a4 = $format === LabelFormat::PDF_A4;
        $pdf = $this->fetchLabels(['barcodes' => $barcodes], $a4, $error);

        if ($pdf === null) {
            return LabelResult::failed($error ?? Craft::t('carrier', 'Hrvatska pošta returned no label.'));
        }

        return LabelResult::ok([new Label([
            'format' => $a4 ? LabelFormat::PDF_A4 : LabelFormat::PDF_A6,
            'data' => $pdf,
        ])]);
    }

    /**
     * `get_shipping_labels`: a GET with a JSON body, by barcodes or by client reference.
     */
    private function fetchLabels(array $selector, bool $a4, ?string &$error = null): ?string
    {
        $response = $this->transport()->request('GET', 'shipment/get_shipping_labels', [
            'json' => array_merge($selector, ['A4' => $a4]),
        ]);

        if (!$response->ok()) {
            $error = $response->errorMessage();

            return null;
        }

        if ((int)$response->at('ResponseStatus', 0) !== 0) {
            $error = self::itemError($response->json_());

            return null;
        }

        $pdf = base64_decode((string)$response->at('PackageLabel', ''), true) ?: '';

        if (!str_starts_with($pdf, '%PDF')) {
            $error = Craft::t('carrier', 'Hrvatska pošta returned no label.');

            return null;
        }

        return $pdf;
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $trackingNumbers = array_values(array_filter(array_map('strval', $trackingNumbers)));

        if ($trackingNumbers === []) {
            return [];
        }

        $response = $this->transport()->request('GET', 'shipment/get_shipment_status', [
            'json' => ['barcodes' => array_map(static fn($n) => ['barcode' => $n], $trackingNumbers)],
        ]);

        if (!$response->ok()) {
            return [];
        }

        $out = [];

        foreach ($response->json_() as $item) {
            if (!is_array($item) || empty($item['Barcode'])) {
                continue;
            }

            $number = (string)$item['Barcode'];
            $scans = (array)($item['PackageScansList'] ?? []);

            if ((int)($item['ResponseStatus'] ?? 0) !== 0 || $scans === []) {
                $out[] = new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
                continue;
            }

            $out[] = $this->trackingInfo($number, $scans);
        }

        return $out;
    }

    private function trackingInfo(string $number, array $scans): TrackingInfo
    {
        usort($scans, static fn($a, $b) => strcmp((string)($a['ScanTime'] ?? ''), (string)($b['ScanTime'] ?? '')));

        $heldAtPoint = false;

        foreach ($scans as $scan) {
            if (in_array((string)($scan['CommentNPA'] ?? ''), self::HELD_AT_POINT, true)) {
                $heldAtPoint = true;
            }
        }

        $events = [];
        $status = TrackingStatus::PRE_TRANSIT;
        $statusText = '';
        $deliveredAt = null;

        foreach ($scans as $scan) {
            $code = strtoupper((string)($scan['Scan'] ?? ''));
            $npa = (string)($scan['CommentNPA'] ?? '');
            $occurredAt = self::parseDate((string)($scan['ScanTime'] ?? ''), self::ZONE);
            $description = trim((string)($scan['ScanDescription'] ?? $code));

            // IZM ("parcel data changed") says nothing about where the parcel is: it inherits
            // the status before it rather than moving the shipment.
            $eventStatus = $code === 'IZM' ? $status : $this->scanStatus($code, $npa, $heldAtPoint);

            $events[] = new TrackingEvent([
                'occurredAt' => $occurredAt,
                'status' => $eventStatus,
                'code' => $code . ($npa !== '' ? ':' . $npa : ''),
                'description' => $description,
                'location' => (string)($scan['Center'] ?? ''),
            ]);

            if ($code !== 'IZM') {
                $status = $eventStatus;
                $statusText = $description;
            }

            if ($eventStatus === TrackingStatus::DELIVERED) {
                $deliveredAt = $occurredAt;
            }
        }

        return new TrackingInfo([
            'trackingNumber' => $number,
            'status' => $status,
            'statusText' => $statusText,
            'deliveredAt' => $status === TrackingStatus::DELIVERED ? $deliveredAt : null,
            'events' => $events,
        ]);
    }

    private function scanStatus(string $code, string $npa, bool $heldAtPoint): string
    {
        if ($code === 'IND') {
            if (in_array($npa, self::IND_READY, true)) {
                return TrackingStatus::READY_FOR_PICKUP;
            }

            if (in_array($npa, self::IND_RETURNED, true)) {
                return TrackingStatus::RETURNED;
            }
        }

        if ($code === 'ODG' && $heldAtPoint) {
            return TrackingStatus::READY_FOR_PICKUP;
        }

        return self::mapStatus($code, self::SCANS, TrackingStatus::IN_TRANSIT);
    }

    // =========================================================================
    // Points
    // =========================================================================

    /**
     * Every post office and Paketomat in one answer, so Carrier syncs it daily and searches its
     * own copy. There is no paging.
     */
    public function pickupPoints(PointQuery $query): PointPage
    {
        if ($query->countryCode !== '' && strtoupper($query->countryCode) !== 'HR') {
            return PointPage::of([]);
        }

        $facility = match (true) {
            $query->types === [] => 'ALL',
            !in_array(PickupPoint::TYPE_LOCKER, $query->types, true) => 'PU',
            !in_array(PickupPoint::TYPE_POST_OFFICE, $query->types, true) => 'PAK',
            default => 'ALL',
        };

        $response = $this->transport()->request('GET', 'delivery_point/get_parcel_delivery_point', [
            'json' => ['facilityType' => $facility, 'nextWeek' => 0, 'searchText' => ''],
        ]);

        if (!$response->ok()) {
            return PointPage::failed($response->errorMessage());
        }

        $points = [];

        foreach ((array)$response->at('paketomatInfoList', []) as $row) {
            if (!is_array($row) || trim((string)($row['code'] ?? '')) === '') {
                continue;
            }

            $points[] = $this->point($row, (string)$row['code'], PickupPoint::TYPE_LOCKER,
                $row['geoLat'] ?? $row['geoLatitude'] ?? null,
                $row['getLng'] ?? $row['geoLng'] ?? $row['getLongitude'] ?? null,
            );
        }

        foreach ((array)$response->at('postaInfoList', []) as $row) {
            // Post offices have no code; HP's documentation implies the postcode is the id.
            if (!is_array($row) || trim((string)($row['zip'] ?? '')) === '') {
                continue;
            }

            $point = $this->point($row, (string)$row['zip'], PickupPoint::TYPE_POST_OFFICE,
                $row['geoLatitude'] ?? $row['geoLat'] ?? null,
                $row['getLongitude'] ?? $row['geoLongitude'] ?? $row['getLng'] ?? null,
            );
            $point->hours = self::hours((array)($row['workingHours'] ?? []));
            $points[] = $point;
        }

        return PointPage::of($points);
    }

    private function point(array $row, string $id, string $type, mixed $lat, mixed $lng): PickupPoint
    {
        return new PickupPoint([
            'id' => trim($id),
            'type' => $type,
            'name' => trim((string)($row['name'] ?? '')) ?: trim($id),
            'street' => trim((string)($row['address'] ?? '')),
            'city' => trim((string)($row['city'] ?? '')),
            'postcode' => trim((string)($row['zip'] ?? '')),
            'countryCode' => 'HR',
            'latitude' => is_numeric($lat) ? (float)$lat : null,
            'longitude' => is_numeric($lng) ? (float)$lng : null,
            'hours' => $type === PickupPoint::TYPE_LOCKER ? '24/7' : '',
            'extra' => array_filter(['tel' => (string)($row['tel'] ?? '')]),
        ]);
    }

    /**
     * `workingHours[]` → "Mon 08:00–19:00; Tue …", Monday first, skipping closed days.
     *
     * The live API sends times as "HH:MM" strings and days out of order (1, 2, 7, 6, …); the
     * documentation shows `{hour, minute, second}` objects, so both are accepted.
     */
    private static function hours(array $days): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $time = static function($t): string {
            if (is_array($t)) {
                return sprintf('%02d:%02d', (int)($t['hour'] ?? 0), (int)($t['minute'] ?? 0));
            }
            if (is_string($t) && preg_match('/^(\d{1,2}):(\d{2})/', trim($t), $m)) {
                return sprintf('%02d:%s', (int)$m[1], $m[2]);
            }
            return '';
        };

        $open = [];
        foreach ($days as $day) {
            if (!is_array($day) || (string)($day['closed'] ?? '0') === '1') {
                continue;
            }

            $from = $time($day['openingTime'] ?? null);
            $to = $time($day['closingTime'] ?? null);
            if ($from === '' || $to === '') {
                continue;
            }

            $open[] = [(int)($day['weekDay'] ?? 0), "{$from}–{$to}"];
        }

        usort($open, static fn($a, $b) => ($a[0] ?: 8) <=> ($b[0] ?: 8));

        return implode('; ', array_map(static fn($d) => ($names[$d[0]] ?? '?') . ' ' . $d[1], $open));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * "26" → [26, address]; "26-PAK" → [26, Paketomat]; "26-PU" → [26, post office].
     *
     * @return array{0:int,1:int}
     */
    private function splitService(string $code): array
    {
        [$service, $suffix] = array_pad(explode('-', $code, 2), 2, '');

        $deliveryType = match (strtoupper($suffix)) {
            'PAK' => 3,
            'PU' => 2,
            default => 1,
        };

        return [(int)($service ?: 26), $deliveryType];
    }

    /**
     * HP wants the reference unique per order, and an order can have more than one shipment.
     */
    private function clientReference(ShipmentRequest $request): string
    {
        $override = trim((string)$request->option('clientReference', ''));

        if ($override !== '') {
            return mb_substr($override, 0, 100);
        }

        $base = $request->reference !== '' ? $request->reference : 'CARRIER';

        return mb_substr($base, 0, 80) . ($request->isReturn ? '-R' : '') . '-' . strtoupper(base_convert((string)time(), 10, 36));
    }

    /**
     * The `sender_*` / `recipient_*` block, cut to HP's field lengths.
     */
    private function party(string $prefix, Address $address, array &$warnings): array
    {
        [$number, $suffix] = self::splitNumber($address->houseNumber);
        $street = $address->street1;

        if ($number === '') {
            [$street, $split] = Addresses::splitHouseNumber($address->street1);
            [$number, $suffix] = self::splitNumber($split);
        }

        $email = trim($address->email);

        if (mb_strlen($email) > 25) {
            $warnings[] = Craft::t('carrier', 'Hrvatska pošta accepts e-mail addresses of at most 25 characters, so {email} was left off the {party}.', [
                'email' => $email,
                'party' => $prefix === 'sender' ? Craft::t('carrier', 'sender') : Craft::t('carrier', 'recipient'),
            ]);
            $email = '';
        }

        $name = $address->contactName();

        if ($address->company !== '' && $address->name !== '' && $prefix === 'sender') {
            $name = $address->company;
        }

        return [
            $prefix . '_name' => mb_substr($name, 0, 50),
            $prefix . '_phone' => mb_substr(Addresses::normalisePhone($address->phone, '385'), 0, 25),
            $prefix . '_email' => $email,
            $prefix . '_street' => mb_substr($street, 0, 50),
            $prefix . '_hnum' => mb_substr($number, 0, 10),
            $prefix . '_hnum_suffix' => mb_substr($suffix, 0, 10),
            $prefix . '_zip' => mb_substr(preg_replace('/\D/', '', $address->postcode), 0, 5),
            $prefix . '_city' => mb_substr($address->city, 0, 25),
        ];
    }

    /** "242a" → ["242", "a"]; "5/B" → ["5", "/B"]. */
    private static function splitNumber(string $houseNumber): array
    {
        $houseNumber = trim($houseNumber);

        if (preg_match('/^(\d+)\s*(.*)$/u', $houseNumber, $m)) {
            return [$m[1], trim($m[2])];
        }

        return [$houseNumber, ''];
    }

    /**
     * The smallest compartment every parcel fits, or null when one fits none.
     */
    private function lockerSize(ShipmentRequest $request): ?string
    {
        $override = strtoupper(trim((string)$request->option('parcelSize', '')));

        if (isset(self::LOCKER_SIZES[$override])) {
            return $override;
        }

        $default = strtoupper((string)$this->setting('defaultLockerSize', 'M')) ?: 'M';
        $chosen = null;
        $order = array_keys(self::LOCKER_SIZES);

        foreach ($request->parcels as $parcel) {
            $size = self::fit($parcel) ?? ($parcel->hasDimensions() ? null : $default);

            if ($size === null) {
                return null;
            }

            if ($chosen === null || array_search($size, $order, true) > array_search($chosen, $order, true)) {
                $chosen = $size;
            }
        }

        return $chosen ?? $default;
    }

    private static function fit(Parcel $parcel): ?string
    {
        if (!$parcel->hasDimensions()) {
            return null;
        }

        $dims = [Units::mmToCm($parcel->lengthMm, 1), Units::mmToCm($parcel->widthMm, 1), Units::mmToCm($parcel->heightMm, 1)];
        sort($dims);

        foreach (self::LOCKER_SIZES as $size => $box) {
            $sortedBox = $box;
            sort($sortedBox);

            if ($dims[0] <= $sortedBox[0] && $dims[1] <= $sortedBox[1] && $dims[2] <= $sortedBox[2]) {
                return $size;
            }
        }

        return null;
    }

    private static function itemError(array $item): string
    {
        $message = trim((string)($item['ErrorMessage'] ?? ''));
        $code = $item['ErrorCode'] ?? null;

        if ($message === '') {
            $message = Craft::t('carrier', 'Hrvatska pošta refused the request.');
        }

        return $code !== null && $code !== '' ? sprintf('%s (%s)', $message, $code) : $message;
    }
}
