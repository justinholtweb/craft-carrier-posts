<?php

namespace justinholtweb\carrierposts\carriers;

use Craft;
use justinholtweb\carrier\auth\OAuth2ClientCredentials;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\LabelResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;

/**
 * Magyar Posta — the MPL API v2 (Hungary).
 *
 * Written against Magyar Posta's own published PDFs (technical description, domestic integration
 * recommendations 2026-08, samples, error codes, label types, tracking). The OpenAPI document is
 * behind the devportal login and was not available; where a detail comes only from the public
 * Sylius client (`gtgt/sylius-mpl-shipping`) it is marked below.
 *
 * Vendor traps:
 *
 * - **Every business call needs `X-Accounting-Code` and a fresh GUID in `X-Request-ID`.** The
 *   request id is validated as a GUID; a malformed one is refused. `call()` mints one per
 *   request; the transport's own retries reuse it, which is what MPL wants for a replayed close.
 * - **Create takes an array and answers with an array**, aligned to the request.
 * - **Errors and warnings share one list** inside an HTTP 200. Warnings (5, 6, 7, 11–14, 33,
 *   44–48, 106–111, 122, 220, 221) still return a tracking number and are passed through as
 *   shipment warnings. Error 33 ("address cannot be identified") is a warning that MPL
 *   nonetheless recommends fixing by deleting and resubmitting.
 * - **MPL ignores unknown JSON keys**, so a misspelt field is silently dropped, not refused.
 * - **The close is mandatory.** Nothing is dispatched until `POST /shipments/close` has built the
 *   day's posting list. Close once per day per dispatch site — Carrier's "Close the day" button —
 *   not per parcel. It returns the manifest PDF and *indicative* per-parcel prices (HUF), which
 *   are kept in the result's data; they are not rates and Carrier does not quote with them.
 * - **After the close, shipments vanish from the shipping API.** Reprint and void then fail with
 *   201/202; only tracking works. Void and reprint before closing.
 * - **COD is `K_UVT` + `services.cod` in whole forints.** Anything but HUF is refused here.
 *   Bank payout (`UV_AT`) needs the sender's account number, which MPL's samples say to always
 *   send. Parcel terminals cap COD at 500,000 HUF.
 * - **Lockers**: `deliveryMode: CS` + `recipient.address.parcelPickupSite` (the point id or name),
 *   one parcel only, at most 20 kg, with a compartment size S/M/L chosen from the parcel's
 *   dimensions (31×25×7, 50×31×16, 50×31×35 cm, published by MPL). PostaPont is `PP`, a post
 *   office `PM`.
 * - **ZPL is only allowed on A6, A5E and A5E_EXTRA** (error 132), so `zpl` is A6.
 * - **One tracking id per synchronous call** — hence a tracking batch of one. Poll at most
 *   hourly; MPL recommends every four hours.
 * - **The sandbox is a mock** and validates less than production.
 * - **Multi-parcel**: up to 16 items per Business Parcel consignment. The consignment number is
 *   what MPL tracks, so it is the first tracking number Carrier gets; the other parcels get their
 *   piece barcodes. All piece barcodes are kept in `data['packageTrackingNumbers']`.
 *
 * UNVERIFIED:
 *
 * - The sandbox token URL (`sandbox.api.posta.hu/oauth2/token`), from the Sylius client.
 * - How `GET /shipments/label` encodes several tracking numbers. Sent as a repeated
 *   `trackingNumbers=` parameter, the way the Sylius client does.
 * - The body of a successful `DELETE /shipments/{n}`. Any 2xx without an error is taken as done.
 * - The delivery-place response beyond `id`, `deliveryplace`, `postCode`, `city`, `address`,
 *   `geocodeLat` and `geocodeLong` (seen in the Sylius client, not in MPL's PDFs).
 * - The public tracking page URL used by `trackingUrl()`.
 *
 * No rates: MPL has no quote endpoint; use the contract price table.
 */
class MagyarPostaCarrier extends Carrier
{
    private const BASE_PRODUCTION = 'https://core.api.posta.hu';
    private const BASE_SANDBOX = 'https://sandbox.api.posta.hu';

    private const ZONE = 'Europe/Budapest';

    /** Error codes MPL types as WARNING: the shipment exists anyway. */
    private const WARNING_CODES = [
        5, 6, 7, 11, 12, 13, 14, 33, 44, 45, 46, 47, 48,
        106, 107, 108, 109, 110, 111, 122, 220, 221,
    ];

    /** Parcel-terminal compartments, cm, as MPL publishes them. */
    private const LOCKER_SIZES = [
        'S' => [7, 25, 31],
        'M' => [16, 31, 50],
        'L' => [35, 31, 50],
    ];

    /** `c43` event category => normalised status, before the text overrides. */
    private const CATEGORIES = [
        '1' => TrackingStatus::PRE_TRANSIT,
        '2' => TrackingStatus::IN_TRANSIT,
        '3' => TrackingStatus::IN_TRANSIT,
        '4' => TrackingStatus::OUT_FOR_DELIVERY,
        '5' => TrackingStatus::DELIVERED,
    ];

    public static function handle(): string
    {
        return 'posta-hu';
    }

    public static function displayName(): string
    {
        return 'Magyar Posta (MPL)';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Europe');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'Hungary’s national post: MPL Business and Postal Parcels to the door, a post office, PostaPont or a parcel terminal, with cash on delivery.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://devportal.posta.hu/en/documentation';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(
                Feature::LABELS, Feature::CANCEL, Feature::REPRINT, Feature::TRACKING,
                Feature::CLOSE_DAY, Feature::COD, Feature::MULTI_PIECE,
            )
            ->points(Capabilities::POINTS_LIST, PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_SHOP, PickupPoint::TYPE_POST_OFFICE)
            ->labelFormats(LabelFormat::PDF_A6, LabelFormat::PDF_A4, LabelFormat::ZPL)
            ->originCountries('HU')
            ->maxParcelWeight(40000)
            ->trackingBatch(1)
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::environment([
                'instructions' => 'The sandbox is a mock service with weaker validation. Each environment has its own key and secret (devportal → Applications).',
            ]),
            Field::text('clientId', 'API key', [
                'required' => true,
                'instructions' => 'The consumer key of your devportal application.',
            ]),
            Field::secret('clientSecret', 'API secret', ['required' => true]),
            Field::text('accountingCode', 'Customer code', [
                'required' => true,
                'instructions' => 'Sent as X-Accounting-Code on every request. It maps one-to-one to the API key.',
            ]),
            Field::text('agreementCode', 'Agreement code', [
                'required' => true,
                'instructions' => 'The contract (agreement) the parcels are posted under, printed on your MPL contract.',
            ]),
            Field::text('bankAccount', 'Bank account for COD', [
                'instructions' => 'Where MPL pays collected cash on delivery, e.g. 11112222-33334444-00000000.',
            ]),
            Field::select('paymentMode', 'COD paid out', [
                'UV_AT' => 'By bank transfer',
                'UV_KP' => 'In cash',
            ]),
            Field::text('webshopId', 'Webshop id', [
                'default' => '1',
                'instructions' => 'Any short id; MPL echoes it back so one account can serve several shops.',
            ]),
            Field::select('packageRetention', 'Days held for collection', [
                '5' => '5 days',
                '10' => '10 days',
            ]),
        ];
    }

    public static function services(): array
    {
        return [
            'A_175_UZL' => ['name' => 'MPL Business Parcel', 'international' => false, 'points' => false],
            'A_175_UZL-CS' => ['name' => 'MPL Business Parcel to a parcel terminal', 'international' => false, 'points' => true],
            'A_175_UZL-PP' => ['name' => 'MPL Business Parcel to a PostaPont', 'international' => false, 'points' => true],
            'A_175_UZL-PM' => ['name' => 'MPL Business Parcel to a post office', 'international' => false, 'points' => true],
            'A_177_MPC' => ['name' => 'MPL Postal Parcel', 'international' => false, 'points' => false],
            'A_177_MPC-PM' => ['name' => 'MPL Postal Parcel to a post office', 'international' => false, 'points' => true],
        ];
    }

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.posta.hu/nyomkovetes/nyitooldal?searchvalue=' . rawurlencode($trackingNumber);
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    private function base(): string
    {
        return $this->isSandbox() ? self::BASE_SANDBOX : self::BASE_PRODUCTION;
    }

    protected function buildAuth(): ?AuthInterface
    {
        // Basic client credentials in the header, not the body.
        return new OAuth2ClientCredentials(
            tokenUrl: fn() => $this->base() . '/oauth2/token',
            sendCredentialsInBody: false,
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri($this->base() . '/v2')
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'X-Accounting-Code' => (string)$this->setting('accountingCode', ''),
            ])
            // "Do not exceed 5 parallel threads" — a gentle cap on one process's pace.
            ->setRateLimit(5);
    }

    /**
     * One business call with the fresh `X-Request-ID` MPL insists on.
     */
    private function call(string $method, string $path, array $options = [], ?string $requestId = null): Response
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'X-Request-ID' => $requestId ?? self::guid(),
        ]);

        return $this->transport()->request($method, $path, $options);
    }

    private static function guid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    protected function probe(): HealthResult
    {
        $response = $this->call('POST', 'mplapi/addresses/cityToZipCode', ['json' => ['city' => 'Szolnok']]);

        if ($response->unauthorized()) {
            return HealthResult::fail(
                Craft::t('carrier', 'Magyar Posta did not accept the API key and secret.'),
                [Craft::t('carrier', 'Sandbox and production use different keys. Check the environment matches the devportal application.')],
            );
        }

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), [
                Craft::t('carrier', 'Check the customer code: MPL refuses a request whose X-Accounting-Code does not belong to the API key.'),
            ]);
        }

        return HealthResult::pass(Craft::t('carrier', 'Connected to Magyar Posta.'), [
            'Environment' => $this->isSandbox() ? 'Sandbox (mock)' : 'Production',
            'Customer code' => (string)$this->setting('accountingCode', ''),
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

        [$basic, $mode] = array_pad(explode('-', $request->serviceCode ?: 'A_175_UZL', 2), 2, 'HA');
        $mode = strtoupper($mode ?: 'HA');

        if ($error = $this->refuseEarly($request, $basic, $mode)) {
            return ShipmentResult::rejected($error);
        }

        [$labelType, $labelFormat] = self::labelParams($this->labelFormatFor($request));

        $services = ['basic' => $basic, 'extra' => [], 'deliveryMode' => $mode];
        $shipment = [
            'developer' => 'Carrier for Craft Commerce',
            'webshopId' => (string)($this->setting('webshopId', '1') ?: '1'),
            'orderId' => mb_substr($request->reference, 0, 50),
            'labelType' => $labelType,
            'labelFormat' => $labelFormat,
            'sender' => $this->sender($from),
            'recipient' => [
                'contact' => self::contact($to),
                'address' => self::address($to),
                'disabled' => false,
            ],
            'item' => [],
            'paymentMode' => (string)($this->setting('paymentMode', 'UV_AT') ?: 'UV_AT'),
            'packageRetention' => (int)($this->setting('packageRetention', 5) ?: 5),
            'groupTogether' => count($request->parcels) > 1,
        ];

        if ($mode !== 'HA') {
            $shipment['recipient']['address']['parcelPickupSite'] = $request->pointId;
        }

        if ($request->hasCod()) {
            $services['extra'] = ['K_UVT', 'K_ENY'];
        }

        foreach ($request->parcels as $i => $parcel) {
            $itemServices = $services;

            // COD and its insurance belong to the consignment; MPL takes them on the first item.
            if ($request->hasCod() && $i === 0) {
                $itemServices['cod'] = (int)round($request->codAmount);
                $itemServices['value'] = (int)round($request->codAmount);
            }

            $item = [
                'customData1' => mb_substr($request->reference, 0, 50),
                'weight' => ['value' => max(1, $parcel->weightG), 'unit' => 'G'],
                'services' => $itemServices,
            ];

            if ($mode === 'CS') {
                $item['size'] = self::lockerSize($parcel->lengthMm, $parcel->widthMm, $parcel->heightMm);
            }

            $shipment['item'][] = $item;
        }

        $transport = $this->transport()->setSafeRetriesOnly(true);

        try {
            $response = $this->call('POST', 'mplapi/shipments', ['json' => [$shipment]]);
        } finally {
            $transport->setSafeRetriesOnly(false);
        }

        if (!$response->ok()) {
            return ShipmentResult::fromResponse($response);
        }

        $result = $response->json_()[0] ?? null;

        if (!is_array($result)) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'Magyar Posta answered without a shipment. Check its portal before trying again.'));
        }

        [$errors, $warnings] = self::splitErrors((array)($result['errors'] ?? []));
        $trackingNumber = trim((string)($result['trackingNumber'] ?? ''));

        if ($trackingNumber === '') {
            return $errors !== []
                ? ShipmentResult::rejected(implode(' ', $errors))
                : ShipmentResult::uncertain(Craft::t('carrier', 'Magyar Posta answered without a tracking number. Check its portal before trying again.'));
        }

        if ($errors !== []) {
            // An error *with* a tracking number: MPL holds a half-accepted consignment. Do not
            // let a retry stack a second one on top.
            return ShipmentResult::uncertain(Craft::t('carrier', 'Magyar Posta created {number} but reported: {errors}', [
                'number' => $trackingNumber,
                'errors' => implode(' ', $errors),
            ]));
        }

        $pieces = array_values(array_map('strval', (array)($result['packageTrackingNumbers'] ?? [])));
        $numbers = [$trackingNumber];

        for ($i = 1; $i < count($request->parcels); $i++) {
            $numbers[] = $pieces[$i] ?? $trackingNumber . '-' . ($i + 1);
        }

        $format = $labelFormat === 'ZPL' ? LabelFormat::ZPL : ($labelType === 'A6inA4' ? LabelFormat::PDF_A4 : LabelFormat::PDF_A6);
        $bytes = base64_decode((string)($result['label'] ?? ''), true) ?: '';

        if (!LabelFormat::bytesMatch($format, $bytes)) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'Magyar Posta created {number} but returned no printable label. Reprint it before closing the day.', [
                'number' => $trackingNumber,
            ]));
        }

        $created = ShipmentResult::ok($trackingNumber, $numbers, [
            new Label([
                'trackingNumber' => $trackingNumber,
                'format' => $format,
                'kind' => $request->isReturn ? 'return' : 'label',
                'data' => $bytes,
            ]),
        ], [
            'packageTrackingNumbers' => $pieces,
            'deliveryMode' => $mode,
        ]);

        $created->warnings = $warnings;

        return $created;
    }

    /**
     * The limits MPL publishes, checked here so the merchant reads them in a sentence rather
     * than as error 34, 57, 66 or 100.
     */
    private function refuseEarly(ShipmentRequest $request, string $basic, string $mode): ?string
    {
        if (!in_array($mode, ['HA', 'PM', 'PP', 'CS'], true)) {
            return Craft::t('carrier', 'Unknown Magyar Posta delivery mode {mode}.', ['mode' => $mode]);
        }

        if ($mode !== 'HA' && $request->pointId === '') {
            return Craft::t('carrier', 'This Magyar Posta service delivers to a pickup point, and none was chosen.');
        }

        if ($basic === 'A_177_MPC' && count($request->parcels) > 1) {
            return Craft::t('carrier', 'An MPL Postal Parcel is one parcel. Use Business Parcel for several.');
        }

        if (count($request->parcels) > 16) {
            return Craft::t('carrier', 'Magyar Posta takes at most 16 parcels in one consignment.');
        }

        if ($mode === 'CS' && count($request->parcels) > 1) {
            return Craft::t('carrier', 'A parcel terminal takes one parcel per consignment.');
        }

        $limitG = match (true) {
            $mode === 'CS', $mode === 'PP' => 20000,
            $mode === 'PM' => 30000,
            $basic === 'A_177_MPC' => 10000,
            default => 40000,
        };

        foreach ($request->parcels as $i => $parcel) {
            if ($parcel->weightG > $limitG) {
                return Craft::t('carrier', 'Parcel {n} is over the {kg} kg limit for this Magyar Posta service.', ['n' => $i + 1, 'kg' => $limitG / 1000]);
            }

            if ($mode === 'CS' && $parcel->hasDimensions() && self::lockerSize($parcel->lengthMm, $parcel->widthMm, $parcel->heightMm) === null) {
                return Craft::t('carrier', 'This parcel is too big for a parcel-terminal compartment.');
            }
        }

        if ($request->hasCod()) {
            $currency = strtoupper($request->codCurrency ?: $request->currency);

            if ($currency !== '' && $currency !== 'HUF') {
                return Craft::t('carrier', 'Magyar Posta collects domestic cash on delivery in forints only, not {currency}.', ['currency' => $currency]);
            }

            $max = $mode === 'CS' ? 500000 : 2000000;

            if ($request->codAmount > $max) {
                return Craft::t('carrier', 'Magyar Posta collects at most {max} HUF on delivery for this service.', ['max' => number_format($max, 0, '.', ' ')]);
            }

            if (($this->setting('paymentMode', 'UV_AT') ?: 'UV_AT') === 'UV_AT' && trim((string)$this->setting('bankAccount', '')) === '') {
                return Craft::t('carrier', 'Cash on delivery paid by bank transfer needs the bank account in the Magyar Posta connection settings.');
            }
        }

        return null;
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $number = $shipment->shipmentId !== '' ? $shipment->shipmentId : ($shipment->trackingNumbers[0] ?? '');

        if ($number === '') {
            return ActionResult::failed(Craft::t('carrier', 'This shipment has no Magyar Posta tracking number to cancel.'));
        }

        $response = $this->call('DELETE', 'mplapi/shipments/' . rawurlencode($number));

        if (!$response->ok()) {
            return ActionResult::failed($response->errorMessage());
        }

        $json = $response->json_();
        $item = array_is_list($json) ? ($json[0] ?? []) : $json;
        [$errors] = self::splitErrors(is_array($item) ? (array)($item['errors'] ?? []) : []);

        if ($errors !== []) {
            return ActionResult::failed(implode(' ', $errors));
        }

        return ActionResult::ok($number);
    }

    public function printLabels(array $shipments, string $format): LabelResult
    {
        $numbers = [];

        foreach ($shipments as $shipment) {
            $numbers[] = $shipment->shipmentId !== '' ? $shipment->shipmentId : ($shipment->trackingNumbers[0] ?? '');
        }

        $numbers = array_values(array_filter($numbers));

        if ($numbers === []) {
            return LabelResult::failed(Craft::t('carrier', 'Nothing to print.'));
        }

        [$labelType, $labelFormat] = self::labelParams($format);
        $query = implode('&', array_map(static fn($n) => 'trackingNumbers=' . rawurlencode($n), $numbers))
            . '&' . http_build_query(['labelType' => $labelType, 'labelFormat' => $labelFormat, 'singleFile' => 'true']);

        $response = $this->call('GET', 'mplapi/shipments/label?' . $query);

        if (!$response->ok()) {
            return LabelResult::failed($response->errorMessage());
        }

        $carrierFormat = $labelFormat === 'ZPL' ? LabelFormat::ZPL : ($labelType === 'A6inA4' ? LabelFormat::PDF_A4 : LabelFormat::PDF_A6);
        $labels = [];
        $problems = [];

        foreach ($response->json_() as $row) {
            if (!is_array($row)) {
                continue;
            }

            [$errors] = self::splitErrors((array)($row['errors'] ?? []));
            $bytes = base64_decode((string)($row['label'] ?? ''), true) ?: '';

            if ($bytes !== '' && LabelFormat::bytesMatch($carrierFormat, $bytes)) {
                $labels[] = new Label([
                    'trackingNumber' => (string)($row['trackingNumber'] ?? ''),
                    'format' => $carrierFormat,
                    'data' => $bytes,
                ]);
            } elseif ($errors !== []) {
                $problems[] = implode(' ', $errors);
            }
        }

        if ($labels === []) {
            return LabelResult::failed($problems !== []
                ? implode(' ', $problems)
                : Craft::t('carrier', 'Magyar Posta returned no label. Labels cannot be reprinted after the day is closed.'));
        }

        return LabelResult::ok($labels);
    }

    // =========================================================================
    // Close
    // =========================================================================

    /**
     * Build the day's posting list. Without it MPL dispatches nothing.
     */
    public function closeDay(array $shipments): ActionResult
    {
        $numbers = [];

        foreach ($shipments as $shipment) {
            $number = $shipment->shipmentId !== '' ? $shipment->shipmentId : ($shipment->trackingNumbers[0] ?? '');

            if ($number !== '') {
                $numbers[] = $number;
            }
        }

        $body = ['checkList' => true, 'checkListWithPrice' => true];

        // No list means "close everything pending", which is also what MPL does by default.
        if ($numbers !== []) {
            $body['trackingNumbers'] = $numbers;
        }

        // Kept so a close that timed out can be replayed with the same id rather than re-run.
        $requestId = self::guid();
        $response = $this->call('POST', 'mplapi/shipments/close', ['json' => $body], $requestId);

        if (!$response->ok()) {
            return ActionResult::failed($response->errorMessage());
        }

        $documents = [];
        $prices = [];
        $problems = [];

        foreach ($response->json_() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $manifest = base64_decode((string)($row['manifest'] ?? ''), true) ?: '';

            if (str_starts_with($manifest, '%PDF')) {
                $documents[] = new Label(['kind' => 'manifest', 'format' => LabelFormat::PDF_A4, 'data' => $manifest]);
            }

            foreach ((array)($row['trackingNrPrices'] ?? []) as $price) {
                if (is_array($price) && isset($price['trackingNumber'])) {
                    $prices[(string)$price['trackingNumber']] = (float)($price['price'] ?? 0);
                }
            }

            [$errors] = self::splitErrors((array)($row['errors'] ?? []));
            array_push($problems, ...$errors);
        }

        if ($documents === [] && $problems !== []) {
            return ActionResult::failed(implode(' ', $problems));
        }

        if ($documents === []) {
            return ActionResult::failed(Craft::t('carrier', 'Magyar Posta did not return a posting list.'));
        }

        return ActionResult::ok(
            $requestId,
            Craft::t('carrier', '{count} shipments closed. Magyar Posta will not show them in the shipping API again; tracking still works.', ['count' => count($numbers) ?: count($prices)]),
            [
                'documents' => $documents,
                // Indicative only, HUF, per tracking number.
                'indicativePrices' => $prices,
                'currency' => 'HUF',
                'warnings' => $problems,
            ],
        );
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach (array_values(array_filter(array_map('strval', $trackingNumbers))) as $number) {
            $response = $this->call('POST', 'nyomkovetes/registered', [
                'json' => ['language' => 'en', 'ids' => $number, 'state' => 'all'],
            ]);

            if (!$response->ok()) {
                continue;
            }

            $records = (array)$response->at('trackAndTrace', []);

            if ($records === []) {
                // An empty list is MPL's "unknown id" — but only when it actually said so.
                if (array_key_exists('trackAndTrace', $response->json_())) {
                    $out[] = new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
                }

                continue;
            }

            $out[] = $this->trackingInfo($number, $records);
        }

        return $out;
    }

    private function trackingInfo(string $number, array $records): TrackingInfo
    {
        $events = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $date = (string)($record['c11'] ?? '');
            $time = (string)($record['c12'] ?? '');
            $occurredAt = preg_match('/^\d{8}$/', $date)
                ? self::parseDate(substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ($time !== '' ? ' ' . $time : ''), self::ZONE)
                : self::parseDate(trim($date . ' ' . $time), self::ZONE);
            $category = (string)($record['c43'] ?? '');
            $text = trim((string)($record['c9'] ?? ''));

            $events[] = new TrackingEvent([
                'occurredAt' => $occurredAt,
                'status' => self::eventStatus($category, $text),
                'code' => $category,
                'description' => $text !== '' ? $text : (string)($record['c10'] ?? ''),
                'location' => trim((string)($record['c13'] ?? '')),
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

    /**
     * The `c43` category, refined by the event text where MPL's categories are too coarse —
     * "ready for collection" is filed under Delivery, a return under Delivered.
     */
    private static function eventStatus(string $category, string $text): string
    {
        $t = mb_strtolower($text);

        $overrides = [
            'returned to sender' => TrackingStatus::RETURNED,
            'being returned' => TrackingStatus::RETURNED,
            'cannot be delivered' => TrackingStatus::EXCEPTION,
            'unsuccessful delivery' => TrackingStatus::EXCEPTION,
            'available for collection' => TrackingStatus::READY_FOR_PICKUP,
            'ready for collection' => TrackingStatus::READY_FOR_PICKUP,
            'directed to post office for storage' => TrackingStatus::READY_FOR_PICKUP,
            'acceptance completed' => TrackingStatus::IN_TRANSIT,
        ];

        // A COD payout is the parcel's last word but not a different status from "delivered".
        if ($category === '5' && str_contains($t, 'cash on delivery')) {
            return TrackingStatus::DELIVERED;
        }

        foreach ($overrides as $needle => $status) {
            if (str_contains($t, $needle)) {
                return $status;
            }
        }

        return self::mapStatus($category, self::CATEGORIES, TrackingStatus::IN_TRANSIT);
    }

    // =========================================================================
    // Points
    // =========================================================================

    /**
     * Every post office, PostaPont and parcel terminal in one answer (`city: ""` returns all).
     */
    public function pickupPoints(PointQuery $query): PointPage
    {
        if ($query->countryCode !== '' && strtoupper($query->countryCode) !== 'HU') {
            return PointPage::of([]);
        }

        $types = [];

        foreach ($query->types ?: [PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_SHOP, PickupPoint::TYPE_POST_OFFICE] as $type) {
            $types[] = match ($type) {
                PickupPoint::TYPE_LOCKER => 'CS',
                PickupPoint::TYPE_SHOP => 'PP',
                default => 'PM',
            };
        }

        $response = $this->call('POST', 'mplapi/deliveryplace', [
            'json' => ['deliveryplacesQuery' => ['city' => ''], 'servicePointType' => array_values(array_unique($types))],
        ]);

        if (!$response->ok()) {
            return PointPage::failed($response->errorMessage());
        }

        $points = [];

        foreach ($response->json_() as $row) {
            $place = is_array($row) ? ($row['deliveryplacesQueryResult'] ?? null) : null;

            if (!is_array($place) || trim((string)($place['id'] ?? '')) === '') {
                continue;
            }

            $kinds = (array)($row['servicePointType'] ?? []);
            $type = match (true) {
                in_array('CS', $kinds, true) => PickupPoint::TYPE_LOCKER,
                in_array('PP', $kinds, true) => PickupPoint::TYPE_SHOP,
                default => PickupPoint::TYPE_POST_OFFICE,
            };

            $points[] = new PickupPoint([
                'id' => trim((string)$place['id']),
                'type' => $type,
                'name' => trim((string)($place['deliveryplace'] ?? '')) ?: trim((string)$place['id']),
                'street' => trim((string)($place['address'] ?? '')),
                'city' => trim((string)($place['city'] ?? '')),
                'postcode' => trim((string)($place['postCode'] ?? '')),
                'countryCode' => 'HU',
                'latitude' => is_numeric($place['geocodeLat'] ?? null) ? (float)$place['geocodeLat'] : null,
                'longitude' => is_numeric($place['geocodeLong'] ?? null) ? (float)$place['geocodeLong'] : null,
                'codAllowed' => true,
                'maxWeightG' => in_array($type, [PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_SHOP], true) ? 20000 : 30000,
                'extra' => array_filter(['servicePointType' => $kinds, 'postCodeOnLabel' => $place['postCodeOnLabel'] ?? null]),
            ]);
        }

        return PointPage::of($points);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array{0:string,1:string} MPL `labelType`, `labelFormat` */
    private static function labelParams(string $format): array
    {
        return match ($format) {
            LabelFormat::PDF_A4 => ['A6inA4', 'PDF'],
            LabelFormat::ZPL => ['A6', 'ZPL'],
            default => ['A6', 'PDF'],
        };
    }

    private function sender(Address $from): array
    {
        $sender = [
            'agreement' => (string)$this->setting('agreementCode', ''),
            'contact' => self::contact($from, true),
            'address' => self::address($from),
        ];

        $account = trim((string)$this->setting('bankAccount', ''));

        if ($account !== '') {
            $sender['accountNo'] = $account;
        }

        return $sender;
    }

    private static function contact(Address $address, bool $preferCompany = false): array
    {
        $name = $preferCompany && $address->company !== '' ? $address->company : $address->contactName();

        return array_filter([
            'name' => mb_substr($name, 0, 150),
            'email' => $address->email,
            'phone' => Addresses::normalisePhone($address->phone, '36'),
        ], static fn($v) => $v !== '');
    }

    private static function address(Address $address): array
    {
        return [
            'postCode' => preg_replace('/\s/', '', $address->postcode),
            'city' => $address->city,
            'address' => trim($address->streetLine() . ($address->street2 !== '' ? ', ' . $address->street2 : '')),
        ];
    }

    /** The smallest compartment a parcel fits; M when it has no dimensions. */
    private static function lockerSize(int $lengthMm, int $widthMm, int $heightMm): ?string
    {
        if ($lengthMm <= 0 || $widthMm <= 0 || $heightMm <= 0) {
            return 'M';
        }

        $dims = [Units::mmToCm($lengthMm, 1), Units::mmToCm($widthMm, 1), Units::mmToCm($heightMm, 1)];
        sort($dims);

        foreach (self::LOCKER_SIZES as $size => $box) {
            sort($box);

            if ($dims[0] <= $box[0] && $dims[1] <= $box[1] && $dims[2] <= $box[2]) {
                return $size;
            }
        }

        return null;
    }

    /**
     * Split MPL's one error list into what stopped the shipment and what only warns about it.
     *
     * @return array{0:string[],1:string[]}
     */
    private static function splitErrors(array $list): array
    {
        $errors = [];
        $warnings = [];

        foreach ($list as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = (string)($error['code'] ?? '');
            $text = trim((string)($error['text_eng'] ?? $error['text'] ?? ''));
            $line = trim(($text !== '' ? $text : Craft::t('carrier', 'Error')) . ($code !== '' ? " ($code)" : '')
                . (!empty($error['parameter']) ? ' [' . $error['parameter'] . ']' : ''));

            if (is_numeric($code) && in_array((int)$code, self::WARNING_CODES, true)) {
                $warnings[] = $line;
            } else {
                $errors[] = $line;
            }
        }

        return [$errors, $warnings];
    }
}
