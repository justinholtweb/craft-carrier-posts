<?php

namespace justinholtweb\carrierposts\carriers;

use Craft;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\LabelResult;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use SimpleXMLElement;

/**
 * ELTA Courier (Greece) — the contract SOAP web services.
 *
 * **Low confidence. Verify against a live account before production.** ELTA Courier publishes
 * no documentation; the WSDLs and a PDF come with the contract. Everything here is from public
 * third-party integrations — the MIT `chinchillabrains/eltaws` PHP client (which ships the
 * PELTT01 WSDL), and the WooCommerce and PrestaShop modules' documentation. ELTA Courier has
 * since been folded into ELTA (Hellenic Post); whether a newer API replaces these services is
 * unknown, but every integration found still uses them.
 *
 * Vendor traps:
 *
 * - **Plain HTTP to a bare IP on port 9003** (`http://212.205.47.226:9003`). Credentials travel
 *   unencrypted in every request body. The host must allow outbound TCP 9003; when it does not,
 *   the symptom is a connect timeout and `probe()` says so.
 * - **`?wsdl` is not served** (it answers `CASKC0023E`), so the envelopes are built by hand.
 *   Micro Focus NetExpress generates one WSDL per service, each with a single operation `READ`,
 *   in the namespace `/<SERVICE>` (`/CREATEAWB`, `/PELB64VG`, `/PELTT01`).
 * - **Credential field names differ per service**: `pel_user_code` / `pel_user_pass` /
 *   `pel_apost_code` / `pel_apost_sub_code` for CREATEAWB, `wpel_code` / `wpel_user` /
 *   `wpel_pass` for the others. `wpel_user` is typed `xs:integer`, so the user code must be
 *   digits only.
 * - **Errors are `st_flag` ≠ 0 with a Greek `st_title`**, inside a normal answer. A SOAP fault
 *   whose code names the client is a refusal; any other fault leaves it unknown whether a
 *   voucher was made.
 * - **No cancellation** through the web services ("Elta Courier Web Services does not offer
 *   cancellation service"): the merchant tells the local branch. So `CANCEL` is not declared.
 * - **No pickup points.** `PELSTATION` returns the store serving a postcode, but its answer is
 *   undocumented and CREATEAWB has no known field to send a parcel to a store, so a picker would
 *   offer something the label cannot honour. `POINTS` is not declared.
 * - **One voucher per consignment.** Several parcels go as one voucher with `pel_temaxia` pieces
 *   and the summed weight; child vouchers are not read (their shape is unknown), which is why
 *   `MULTI_PIECE` is not declared.
 * - **Labels are PDF only**, server-generated, `paper_size` 0 = A4, 1 = A6. The A4 layout does
 *   not line up with perforated label sheets.
 * - **Buying a label is two calls** (CREATEAWB, then PELB64VG). If the voucher is made and the
 *   label call fails, the result is *uncertain* and names the voucher, because ELTA cannot void
 *   it and buying again would make a second one.
 * - **Tracking has no status codes**, only Greek titles; they are mapped by keyword, and a
 *   non-empty `pod_date` (proof of delivery) means delivered whatever the titles say.
 * - Test credentials (`9999999` / `9999999` / customer `999999999`) exist, on the same endpoint;
 *   cash on delivery is not available with them.
 *
 * UNVERIFIED:
 *
 * - **The CREATEAWB response**: `st_flag`, `st_title`, and the voucher in `vg_code`, inferred
 *   from the naming of the published PELTT01 WSDL and the label call's input.
 * - **The PELB64VG response element** holding the PDF, expected `b64_string`.
 * - Which credential the label call's `pel_code` is (taken as the customer code).
 * - The SOAP endpoint path and `SOAPAction` (the root URL and `/<SERVICE>/READ`); both are
 *   settings in case the contract WSDL says otherwise.
 * - Whether body elements are namespace-qualified (they are sent qualified).
 * - Number formats (sent with a dot decimal separator) and field lengths (truncated
 *   conservatively).
 * - The Greek status wording in `KEYWORDS`, and the date format of `web_date`.
 */
class EltaCarrier extends Carrier
{
    public const DEFAULT_ENDPOINT = 'http://212.205.47.226:9003';

    private const ZONE = 'Europe/Athens';

    /** Greek title fragment → status, first match wins. UNVERIFIED wording. */
    private const KEYWORDS = [
        'ΕΠΙΣΤΡΟΦ' => TrackingStatus::RETURNED,
        'ΑΡΝΗΣΗ' => TrackingStatus::EXCEPTION,
        'ΑΠΩΝ' => TrackingStatus::EXCEPTION,
        'ΛΑΘΟΣ ΔΙΕΥΘΥΝΣΗ' => TrackingStatus::EXCEPTION,
        'ΠΑΡΑΔΟΘΗΚΕ' => TrackingStatus::DELIVERED,
        'ΠΡΟΣ ΠΑΡΑΛΑΒΗ' => TrackingStatus::READY_FOR_PICKUP,
        'ΘΥΡΙΔΑ' => TrackingStatus::READY_FOR_PICKUP,
        'ΣΕ ΔΙΑΝΟΜΗ' => TrackingStatus::OUT_FOR_DELIVERY,
        'ΠΡΟΣ ΔΙΑΝΟΜΗ' => TrackingStatus::OUT_FOR_DELIVERY,
        'ΠΑΡΑΛΑΒΗ' => TrackingStatus::IN_TRANSIT,
        'ΑΠΟΣΤΟΛΗ' => TrackingStatus::IN_TRANSIT,
        'ΔΙΑΚΙΝΗΣΗ' => TrackingStatus::IN_TRANSIT,
        'ΑΦΙΞΗ' => TrackingStatus::IN_TRANSIT,
    ];

    public static function handle(): string
    {
        return 'elta';
    }

    public static function displayName(): string
    {
        return 'ELTA Courier';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Europe');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'Greece’s postal courier: vouchers with cash on delivery and tracking, through ELTA Courier’s contract web services.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://www.elta-courier.gr/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::LABELS, Feature::REPRINT, Feature::TRACKING, Feature::COD)
            ->labelFormats(LabelFormat::PDF_A6, LabelFormat::PDF_A4)
            ->originCountries('GR')
            ->trackingBatch(1);
    }

    public static function settingsFields(): array
    {
        return [
            Field::heading('Web services account', 'From your ELTA Courier contract (ask your branch, +30 210 607 3000 or info@elta-courier.gr). The service uses plain HTTP: these values cross the internet unencrypted.'),
            Field::secret('userCode', 'User code', [
                'required' => true,
                'instructions' => 'Digits only. The test account is 9999999.',
            ]),
            Field::secret('password', 'Password', ['required' => true]),
            Field::text('customerCode', 'Customer (sender) code', [
                'required' => true,
                'instructions' => 'Your ELTA Courier customer code. The test account is 999999999.',
            ]),
            Field::text('customerSubCode', 'Customer sub-code', [
                'instructions' => 'Only if ELTA Courier gave you one for this dispatch point.',
            ]),
            Field::text('serviceCode', 'Service code', [
                'default' => '1',
                'instructions' => 'The pel_service value from your contract. 1 is the standard service.',
            ]),
            Field::heading('Endpoint', 'Change these only if your contract WSDL names a different address.'),
            Field::text('endpoint', 'Endpoint', [
                'default' => self::DEFAULT_ENDPOINT,
                'instructions' => 'Your host must allow outbound connections to port 9003.',
            ]),
        ];
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'ELTA Courier', 'international' => false, 'points' => false],
        ];
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    private function endpoint(): string
    {
        $endpoint = trim((string)$this->setting('endpoint', ''));

        return $endpoint !== '' && preg_match('#^https?://#', $endpoint) ? $endpoint : self::DEFAULT_ENDPOINT;
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri($this->endpoint())
            ->setTimeout(30);
    }

    /**
     * One SOAP `READ` against a service. Credentials are in `$fields`, never in headers.
     */
    private function soap(string $service, array $fields): Response
    {
        $ns = '/' . $service;
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= sprintf('<ns:%1$s>%2$s</ns:%1$s>', $name, htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="' . $ns . '">'
            . '<soapenv:Header/><soapenv:Body><ns:READ>' . $body . '</ns:READ></soapenv:Body></soapenv:Envelope>';

        return $this->transport()->request('POST', '', [
            'body' => $envelope,
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"' . $ns . '/READ"',
            ],
        ]);
    }

    /** The first element of that name anywhere in a namespace-stripped answer. */
    private static function value(?SimpleXMLElement $xml, string $name): ?string
    {
        if ($xml === null) {
            return null;
        }

        $found = $xml->xpath('//' . $name);

        return $found ? trim((string)$found[0]) : null;
    }

    private function userCode(): string
    {
        return preg_replace('/\D/', '', (string)$this->setting('userCode', ''));
    }

    /** The `wpel_*` credential trio the non-create services take. */
    private function wpel(): array
    {
        return [
            'wpel_code' => (string)$this->setting('customerCode', ''),
            'wpel_user' => $this->userCode(),
            'wpel_pass' => (string)$this->setting('password', ''),
        ];
    }

    protected function probe(): HealthResult
    {
        // A tracking lookup for a voucher that cannot exist: cheap, and it carries credentials.
        $response = $this->soap('PELTT01', $this->wpel() + [
            'wpel_vg' => '0000000000000',
            'wpel_ref' => '',
            'wpel_flag' => '1',
        ]);

        if ($response->status === 0) {
            return HealthResult::fail(
                Craft::t('carrier', 'ELTA Courier could not be reached.'),
                [Craft::t('carrier', 'Your host is probably blocking outbound port 9003. Ask them to allow plain HTTP to 212.205.47.226:9003.')],
            );
        }

        $xml = $response->xmlBody();
        $fault = self::value($xml, 'faultstring');

        if (!$response->ok() || $fault !== null) {
            return HealthResult::fail($fault ?: $response->errorMessage());
        }

        if (self::value($xml, 'st_flag') === null) {
            return HealthResult::fail(Craft::t('carrier', 'ELTA Courier answered, but not with the expected SOAP reply.'), [
                Craft::t('carrier', 'Check the endpoint against the WSDL in your contract bundle.'),
            ]);
        }

        // ELTA answers in Greek, and "unknown voucher" and "bad password" look alike from here.
        return HealthResult::pass(Craft::t('carrier', 'ELTA Courier answered on port 9003.'), [
            'Reply' => (string)self::value($xml, 'st_title'),
            'Note' => 'Plain HTTP: credentials are not encrypted in transit.',
        ]);
    }

    // =========================================================================
    // Labels
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $to = $request->shipTo;

        if ($to === null || $request->parcels === []) {
            return ShipmentResult::rejected(Craft::t('carrier', 'A shipment needs a recipient and at least one parcel.'));
        }

        if ($request->hasCod()) {
            $currency = strtoupper($request->codCurrency ?: $request->currency);

            if ($currency !== '' && $currency !== 'EUR') {
                return ShipmentResult::rejected(Craft::t('carrier', 'ELTA Courier collects cash on delivery in euros only, not {currency}.', ['currency' => $currency]));
            }
        }

        $phone = preg_replace('/\D/', '', Addresses::normalisePhone($to->phone, '30'));

        $fields = [
            'pel_user_code' => $this->userCode(),
            'pel_user_pass' => (string)$this->setting('password', ''),
            'pel_apost_code' => (string)$this->setting('customerCode', ''),
            'pel_apost_sub_code' => (string)$this->setting('customerSubCode', ''),
            'pel_user_lang' => '',
            'pel_paral_name' => mb_substr($to->contactName(), 0, 40),
            'pel_paral_address' => mb_substr(trim($to->streetLine() . ($to->street2 !== '' ? ', ' . $to->street2 : '')), 0, 40),
            'pel_paral_area' => mb_substr($to->city, 0, 30),
            'pel_paral_tk' => preg_replace('/\s/', '', $to->postcode),
            'pel_paral_thl_1' => mb_substr($phone, 0, 20),
            'pel_paral_thl_2' => '',
            'pel_service' => (string)($this->setting('serviceCode', '1') ?: '1'),
            'pel_temaxia' => str_pad((string)count($request->parcels), 3, '0', STR_PAD_LEFT),
            'pel_baros' => number_format(Units::gramsToKg(max(1, $request->totalWeightG()), 2), 2, '.', ''),
            'pel_ant_poso' => $request->hasCod() ? number_format($request->codAmount, 2, '.', '') : '0',
            'pel_asf_poso' => '0',
            'pel_paral_sxolia' => mb_substr($request->instructions, 0, 100),
            'pel_ref_no' => mb_substr($request->reference, 0, 20),
        ];

        $transport = $this->transport()->setSafeRetriesOnly(true);

        try {
            $response = $this->soap('CREATEAWB', $fields);
        } finally {
            $transport->setSafeRetriesOnly(false);
        }

        $xml = $response->xmlBody();
        $fault = self::value($xml, 'faultstring');

        if ($fault !== null) {
            // A Client fault is ELTA refusing what it was sent; anything else may have half-run.
            $faultCode = (string)self::value($xml, 'faultcode');

            return stripos($faultCode, 'client') !== false
                ? ShipmentResult::rejected($fault)
                : ShipmentResult::uncertain($fault);
        }

        if (!$response->ok()) {
            return ShipmentResult::fromResponse($response);
        }

        $flag = self::value($xml, 'st_flag');

        if ($flag === null) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'ELTA Courier answered without a voucher. Check with your branch before trying again.'));
        }

        if ((int)$flag !== 0) {
            return ShipmentResult::rejected((string)(self::value($xml, 'st_title') ?: Craft::t('carrier', 'ELTA Courier refused the voucher.')));
        }

        $voucher = (string)self::value($xml, 'vg_code');

        if ($voucher === '') {
            return ShipmentResult::uncertain(Craft::t('carrier', 'ELTA Courier accepted the voucher but did not return its number. Check with your branch before trying again.'));
        }

        $format = $this->labelFormatFor($request);
        $pdf = $this->fetchLabel($voucher, $format, $error);

        if ($pdf === null) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'ELTA Courier created voucher {voucher} but its label could not be fetched ({error}). ELTA cannot void it: record it as created, then reprint.', [
                'voucher' => $voucher,
                'error' => $error,
            ]));
        }

        return ShipmentResult::ok($voucher, [$voucher], [
            new Label([
                'trackingNumber' => $voucher,
                'format' => $format,
                'data' => $pdf,
            ]),
        ], ['pieces' => count($request->parcels)]);
    }

    public function printLabels(array $shipments, string $format): LabelResult
    {
        $format = $format === LabelFormat::PDF_A4 ? LabelFormat::PDF_A4 : LabelFormat::PDF_A6;
        $labels = [];

        foreach ($shipments as $shipment) {
            $voucher = $shipment->shipmentId !== '' ? $shipment->shipmentId : ($shipment->trackingNumbers[0] ?? '');

            if ($voucher === '') {
                continue;
            }

            // One call per voucher: PELB64VG takes a single one, so there is no merged batch.
            $pdf = $this->fetchLabel($voucher, $format, $error);

            if ($pdf === null) {
                return LabelResult::failed($error);
            }

            $labels[] = new Label(['trackingNumber' => $voucher, 'format' => $format, 'data' => $pdf]);
        }

        return $labels === [] ? LabelResult::failed(Craft::t('carrier', 'Nothing to print.')) : LabelResult::ok($labels);
    }

    private function fetchLabel(string $voucher, string $format, ?string &$error = null): ?string
    {
        $response = $this->soap('PELB64VG', [
            'pel_code' => (string)$this->setting('customerCode', ''),
            'wpel_user' => $this->userCode(),
            'wpel_pass' => (string)$this->setting('password', ''),
            'wpel_vg' => $voucher,
            'paper_size' => $format === LabelFormat::PDF_A4 ? '0' : '1',
        ]);

        $xml = $response->xmlBody();

        if (!$response->ok() || $xml === null) {
            $error = self::value($xml, 'faultstring') ?? $response->errorMessage();

            return null;
        }

        if ((int)(self::value($xml, 'st_flag') ?? 1) !== 0) {
            $error = (string)(self::value($xml, 'st_title') ?: Craft::t('carrier', 'ELTA Courier refused the label.'));

            return null;
        }

        $pdf = base64_decode((string)self::value($xml, 'b64_string'), true) ?: '';

        if (!str_starts_with($pdf, '%PDF')) {
            $error = Craft::t('carrier', 'ELTA Courier returned no PDF.');

            return null;
        }

        return $pdf;
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach (array_values(array_filter(array_map('strval', $trackingNumbers))) as $number) {
            $response = $this->soap('PELTT01', $this->wpel() + [
                'wpel_vg' => $number,
                'wpel_ref' => '',
                'wpel_flag' => '1',
            ]);

            $xml = $response->xmlBody();

            if (!$response->ok() || $xml === null || self::value($xml, 'faultstring') !== null) {
                continue;
            }

            $flag = self::value($xml, 'st_flag');

            if ($flag === null) {
                continue;
            }

            if ((int)$flag !== 0) {
                $out[] = new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
                continue;
            }

            $out[] = $this->trackingInfo($number, $xml);
        }

        return $out;
    }

    private function trackingInfo(string $number, SimpleXMLElement $xml): TrackingInfo
    {
        $events = [];

        foreach ($xml->xpath('//web_status') ?: [] as $row) {
            $title = trim((string)$row->web_status_title);

            if ($title === '' && trim((string)$row->web_date) === '') {
                continue;
            }

            $status = self::keywordStatus($title);

            if ($status === null) {
                $status = TrackingStatus::IN_TRANSIT;
                $this->note('ELTA Courier tracking title not mapped: ' . $title);
            }

            $events[] = new TrackingEvent([
                'occurredAt' => self::date((string)$row->web_date, (string)$row->web_time),
                'status' => $status,
                'code' => '',
                'description' => $title,
                'location' => trim((string)$row->web_station),
            ]);
        }

        $podDate = (string)self::value($xml, 'pod_date');
        $deliveredAt = null;
        $hasDeliveryScan = false;

        if ($podDate !== '') {
            $deliveredAt = self::date($podDate, (string)self::value($xml, 'pod_time'));
            $hasDeliveryScan = array_filter($events, static fn(TrackingEvent $e) => $e->status === TrackingStatus::DELIVERED) !== [];
        }

        // Proof of delivery is its own block, not a scan. Add it as an event only when no title
        // already said "delivered", so the timeline does not show the parcel arriving twice.
        if ($podDate !== '' && !$hasDeliveryScan) {
            $events[] = new TrackingEvent([
                'occurredAt' => $deliveredAt,
                'status' => TrackingStatus::DELIVERED,
                'code' => 'POD',
                'description' => trim('ΠΑΡΑΔΟΘΗΚΕ ' . self::value($xml, 'pod_name')),
            ]);
        }

        usort($events, static fn(TrackingEvent $a, TrackingEvent $b) => ($a->occurredAt?->getTimestamp() ?? 0) <=> ($b->occurredAt?->getTimestamp() ?? 0));
        $last = end($events) ?: null;
        $status = $podDate !== '' ? TrackingStatus::DELIVERED : ($last?->status ?? TrackingStatus::UNKNOWN);

        return new TrackingInfo([
            'trackingNumber' => $number,
            'status' => $status,
            'statusText' => $last?->description ?? '',
            'deliveredAt' => $deliveredAt,
            'signedBy' => (string)self::value($xml, 'pod_name'),
            'events' => $events,
        ]);
    }

    private static function keywordStatus(string $title): ?string
    {
        $t = mb_strtoupper($title);

        // Greek capitals are written with and without tonos; compare without.
        $t = strtr($t, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω']);

        foreach (self::KEYWORDS as $needle => $status) {
            if (str_contains($t, $needle)) {
                return $status;
            }
        }

        return null;
    }

    /** `web_date` arrives as d/m/Y, Y-m-d or Ymd depending on who you ask. */
    private static function date(string $date, string $time): ?\DateTime
    {
        $date = trim($date);
        $time = trim($time);

        if ($date === '') {
            return null;
        }

        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $date, $m)) {
            $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
            $date = "$m[1]-$m[2]-$m[3]";
        }

        if (preg_match('/^(\d{2})(\d{2})(\d{2})?$/', $time, $m)) {
            $time = $m[1] . ':' . $m[2] . (isset($m[3]) ? ':' . $m[3] : '');
        }

        return self::parseDate(trim($date . ' ' . $time), self::ZONE);
    }
}
