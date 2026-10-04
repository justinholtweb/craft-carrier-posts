<?php
/**
 * Recorded happy-path answers for the conformance suite (craft-carrier/tests/integration/carriers.php).
 *
 * Each double answers with the vendor's own documented sample, trimmed but structurally
 * faithful: Hrvatska pošta and Magyar Posta from their published documentation, Pošta
 * Slovenije from its REST-client script (token) and the legacy WCF field names (tracking, whose
 * new shape is unpublished), the posta.si point finder from a live probe, and ELTA Courier from
 * the shapes in the eltaws client's PELTT01 WSDL. Where a sample is inferred rather than
 * published, the carrier's docblock says so.
 *
 * `Pdf::text()` stands in for a PDF and a short `^XA…^XZ` for ZPL.
 */

use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\helpers\Pdf;

$pdf = static fn(string $title, string $number) => base64_encode(Pdf::text([$title, $number], 297.6, 419.5));

return [

    // =====================================================================================
    // Hrvatska pošta — HP_Shipping_Service_Api__Documentation.docx (v1, 2022-11-28)
    // =====================================================================================

    'posta-hr' => static function(string $method, string $url, array $options) use ($pdf): Response {
        $body = $options['json'] ?? [];

        if (str_contains($url, ':9000/api/authentication/client_auth')) {
            return Response::json(200, ['accessToken' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0Y2sifQ.fixture', 'expiresIn' => '14400']);
        }

        if (str_contains($url, '/shipment/create_shipment_orders')) {
            $order = $body['parcels'][0] ?? [];
            $packages = [];

            foreach (($order['packages'] ?? []) as $i => $package) {
                $packages[] = [
                    'barcode' => sprintf('LE9000021%02dHR', 11 + $i),
                    'barcode_type' => 1,
                    'barcode_client' => $package['barcode_client'] ?? '',
                    'weight' => $package['weight'] ?? 0,
                ];
            }

            return Response::json(200, [
                'ShipmentOrdersList' => [[
                    'ClientReferenceNumber' => $order['client_reference_number'] ?? 'ORD-1001',
                    'ResponseStatus' => 0,
                    'Packages' => $packages,
                    'ErrorCode' => null,
                    'ErrorMessage' => null,
                    'MessageID' => '',
                ]],
                'ShipmentsLabel' => $pdf('HRVATSKA POSTA', 'LE900002111HR'),
            ]);
        }

        if (str_contains($url, '/shipment/get_shipping_labels')) {
            return Response::json(200, [
                'PackageLabel' => $pdf('HRVATSKA POSTA', 'LE900002111HR'),
                'ResponseStatus' => 0,
                'ErrorCode' => null,
                'ErrorMessage' => null,
            ]);
        }

        if (str_contains($url, '/shipment/cancel_shipment_orders')) {
            return Response::json(200, [[
                'ClientReferenceNumber' => $body['parcels'][0]['client_reference_number'] ?? '',
                'ResponseStatus' => 0,
                'ErrorCode' => null,
                'ErrorMessage' => null,
            ]]);
        }

        if (str_contains($url, '/shipment/get_shipment_status')) {
            $out = [];

            foreach (($body['barcodes'] ?? []) as $row) {
                $out[] = [
                    'Barcode' => $row['barcode'],
                    'PackageScansList' => [
                        ['Scan' => 'NOV', 'ScanDescription' => 'Definirani podaci o pošiljci', 'ScanTime' => '2022-11-16T11:15:33.257', 'Center' => 'ROOT', 'Comment' => null, 'CommentNPA' => null],
                        ['Scan' => 'PRI', 'ScanDescription' => 'Preuzeto od pošiljatelja', 'ScanTime' => '2022-11-16T15:02:10.110', 'Center' => '10000', 'Comment' => null, 'CommentNPA' => null],
                        ['Scan' => 'ZAD', 'ScanDescription' => 'Zaduženo za dostavu', 'ScanTime' => '2022-11-17T07:40:00.000', 'Center' => '21000', 'Comment' => null, 'CommentNPA' => null],
                        ['Scan' => 'IOD', 'ScanDescription' => 'Uručeno', 'ScanTime' => '2022-11-17T12:31:45.000', 'Center' => '21000', 'Comment' => null, 'CommentNPA' => '5000002'],
                    ],
                    'ResponseStatus' => 0,
                    'ErrorCode' => null,
                    'ErrorMessage' => null,
                ];
            }

            return Response::json(200, $out);
        }

        if (str_contains($url, '/delivery_point/get_parcel_delivery_point')) {
            return Response::json(200, [
                'postaInfoList' => [[
                    'type' => 'PU',
                    'name' => 'Zagreb 1',
                    'zip' => '10101',
                    'city' => 'Zagreb',
                    'address' => 'Jurišićeva 13',
                    'tel' => '072 303 304',
                    'fax' => '',
                    'geoLatitude' => '45.8132',
                    'getLongitude' => '15.9797',
                    'workingHours' => [
                        ['weekDay' => 1, 'date' => '2026-10-05', 'openingTime' => ['hour' => 7, 'minute' => 0, 'second' => 0], 'closingTime' => ['hour' => 20, 'minute' => 0, 'second' => 0], 'closed' => '0'],
                        ['weekDay' => 7, 'date' => '2026-10-11', 'openingTime' => ['hour' => 0, 'minute' => 0, 'second' => 0], 'closingTime' => ['hour' => 0, 'minute' => 0, 'second' => 0], 'closed' => '1'],
                    ],
                ]],
                'paketomatInfoList' => [[
                    'type' => 'PAK',
                    'name' => 'Paketomat Zagreb Ilica',
                    'zip' => '10000',
                    'city' => 'Zagreb',
                    'address' => 'Ilica 242',
                    'geoLat' => '45.8143',
                    'getLng' => '15.9420',
                    'code' => 'P1001',
                ]],
            ]);
        }

        return Response::json(404, ['message' => "No fixture for $method $url"]);
    },

    // =====================================================================================
    // Magyar Posta — MPLAPI_technical_description.pdf, mplapi_samples.pdf, Tracking_technical_description.pdf
    // =====================================================================================

    'posta-hu' => static function(string $method, string $url, array $options) use ($pdf): Response {
        $body = $options['json'] ?? [];

        if (str_contains($url, '/oauth2/token')) {
            return Response::json(200, ['access_token' => 'fixture-mpl-token', 'issued_at' => 1592910455065, 'expires_in' => 1799, 'token_type' => 'Bearer']);
        }

        if (str_contains($url, '/mplapi/shipments/close')) {
            return Response::json(200, [[
                'manifest' => base64_encode(Pdf::text(['MPL POSTING LIST'], 595.3, 841.9)),
                'trackingNrPrices' => array_map(static fn($n) => ['trackingNumber' => $n, 'price' => 1250], $body['trackingNumbers'] ?? []),
                'errors' => [],
            ]]);
        }

        if (str_contains($url, '/mplapi/shipments/label')) {
            $zpl = str_contains($url, 'labelFormat=ZPL');

            return Response::json(200, [[
                'trackingNumber' => 'PKAAA50058206',
                'label' => $zpl ? base64_encode("^XA^FO40,40^A0N,40,40^FDPKAAA50058206^FS^XZ") : $pdf('MPL', 'PKAAA50058206'),
                'errors' => [],
            ]]);
        }

        if ($method === 'DELETE' && str_contains($url, '/mplapi/shipments/')) {
            return Response::json(200, []);
        }

        if ($method === 'POST' && preg_match('#/mplapi/shipments$#', $url)) {
            $shipment = $body[0] ?? [];
            $pieces = [];

            foreach (($shipment['item'] ?? []) as $i => $item) {
                $pieces[] = sprintf('PKAAA50058206%03d1138001500', $i + 1);
            }

            $label = ($shipment['labelFormat'] ?? 'PDF') === 'ZPL'
                ? base64_encode("^XA^FO40,40^A0N,40,40^FDPKAAA50058206^FS^XZ")
                : $pdf('MPL', 'PKAAA50058206');

            return Response::json(200, [[
                'webshopId' => $shipment['webshopId'] ?? '1',
                'trackingNumber' => 'PKAAA50058206',
                'packageTrackingNumbers' => $pieces,
                'label' => $label,
                // A WARNING still returns a tracking number (code 108: phone auto-corrected).
                'errors' => [['code' => '108', 'parameter' => 'recipient.contact.phone', 'text' => 'Telefonszám javítva', 'text_eng' => 'Phone number corrected']],
            ]]);
        }

        if (str_contains($url, '/nyomkovetes/')) {
            $id = (string)($body['ids'] ?? '');

            return Response::json(200, ['trackAndTrace' => [
                ['c1' => $id, 'c2' => 'A_175_UZL', 'c4' => 'HA', 'c9' => 'Acceptance completed', 'c10' => 'Accepting', 'c11' => '20261001', 'c12' => '14:02:11', 'c13' => 'Budapest 62', 'c43' => '1', 'c61' => '1'],
                ['c1' => $id, 'c2' => 'A_175_UZL', 'c4' => 'HA', 'c9' => 'Item processed at sorting centre', 'c10' => 'Processing', 'c11' => '20261001', 'c12' => '21:40:00', 'c13' => 'BLK', 'c43' => '2', 'c61' => '2'],
                ['c1' => $id, 'c2' => 'A_175_UZL', 'c4' => 'HA', 'c9' => 'Item handed over to delivery man', 'c10' => 'Delivery', 'c11' => '20261002', 'c12' => '07:15:00', 'c13' => 'Győr 1', 'c43' => '4', 'c61' => '4'],
                ['c1' => $id, 'c2' => 'A_175_UZL', 'c4' => 'HA', 'c9' => 'Delivered', 'c10' => 'Delivered', 'c11' => '20261002', 'c12' => '11:03:52', 'c13' => 'Győr 1', 'c43' => '5', 'c56' => 'Addressee', 'c61' => '5'],
            ]]);
        }

        if (str_contains($url, '/mplapi/deliveryplace')) {
            return Response::json(200, [
                ['deliveryplacesQueryResult' => ['id' => '107206', 'deliveryplace' => '345 sz. automata - Szolnok Auchan', 'postCode' => '5000', 'city' => 'Szolnok', 'address' => 'Felső Szandai rét 1.', 'geocodeLat' => 47.1659, 'geocodeLong' => 20.1580, 'postCodeOnLabel' => '5000'], 'servicePointType' => ['CS']],
                ['deliveryplacesQueryResult' => ['id' => '43078', 'deliveryplace' => 'MOL Töltőállomás', 'postCode' => '1138', 'city' => 'Budapest', 'address' => 'Váci út 1', 'geocodeLat' => 47.5, 'geocodeLong' => 19.0, 'postCodeOnLabel' => '1138'], 'servicePointType' => ['PP']],
            ]);
        }

        if (str_contains($url, '/mplapi/addresses/cityToZipCode')) {
            return Response::json(200, [['city' => 'Szolnok', 'zipCode' => '5000']]);
        }

        return Response::json(404, ['message' => "No fixture for $method $url"]);
    },

    // =====================================================================================
    // Pošta Slovenije — eSledenje-pogodbeniki-core-api.pdf (token), legacy WCF names (tracking),
    // posta.si office finder (live-probed 2026-10-03)
    // =====================================================================================

    // The point finder is opt-in (an unofficial feed), so the fixture switches it on.
    'posta-si' => [
        'settings' => ['usePointFeed' => true],
        'double' => static function(string $method, string $url, array $options): Response {
        if (str_contains($url, '/api/oauth.token')) {
            // The answer *is* the JWT, as a JSON string. exp = 2100-01-01.
            return new Response(200, ['Content-Type' => ['application/json']], '"eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjQxMDI0NDQ4MDB9.fixture"');
        }

        if (str_contains($url, '/api/easistecho')) {
            return new Response(200, ['Content-Type' => ['text/plain']], 'OK');
        }

        if (str_contains($url, '/api/parcels.select.barcode.queue')) {
            $out = [];

            foreach (($options['json'] ?? []) as $barcode) {
                $out[] = [
                    'CrtnaKoda' => $barcode,
                    'DogodkiPosiljke' => [
                        ['DatumDogodka' => '2026-09-29T09:12:00', 'Status' => 'SPR', 'OpisStatusaStranka' => 'Sprejem pošiljke', 'PostaId' => '1102', 'PostaNaziv' => 'Ljubljana', 'PrevodStatusa' => 'Item accepted'],
                        ['DatumDogodka' => '2026-09-30T06:40:00', 'Status' => 'DOS', 'OpisStatusaStranka' => 'Pošiljka je v dostavi', 'PostaId' => '2000', 'PostaNaziv' => 'Maribor', 'PrevodStatusa' => 'Out for delivery'],
                        ['DatumDogodka' => '2026-09-30T12:05:00', 'Status' => 'VRO', 'OpisStatusaStranka' => 'Pošiljka je vročena', 'PostaId' => '2000', 'PostaNaziv' => 'Maribor', 'PrevodStatusa' => 'Delivered'],
                    ],
                ];
            }

            return Response::json(200, $out);
        }

        if (str_contains($url, '/PostOfficesService.svc/PostalOffice/')) {
            return Response::json(200, [
                ['PostalId' => '1101', 'PostalClassNumAndTitle' => '1101 Ljubljana', 'PostalAddress' => 'Slovenska cesta 32, 1000 Ljubljana', 'VrstaEnote' => 0, 'PostalClassGroup' => 1, 'Pin' => ['GPSx' => '46.0514', 'GPSy' => '14.5033'], 'FormatWorkingDays' => ['pon–pet: 08:00–19:00', 'sob: 08:00–12:00']],
                ['PostalId' => 'SIP1701', 'PostalClassNumAndTitle' => 'PS Paketomat 1701 Ljubljana', 'PostalAddress' => 'Trg OF 13, 1000 Ljubljana', 'VrstaEnote' => 2, 'PostalClassGroup' => 32, 'Pin' => ['GPSx' => '46.0576', 'GPSy' => '14.5108'], 'FormatWorkingDays' => ['vsak dan: 00:00–24:00']],
            ]);
        }

        return Response::json(404, ['message' => "No fixture for $method $url"]);
    },
    ],

    // =====================================================================================
    // ELTA Courier — shapes from chinchillabrains/eltaws (PELTT01.wsdl); CREATEAWB/PELB64VG
    // answers follow the same convention (UNVERIFIED, see EltaCarrier)
    // =====================================================================================

    'elta' => static function(string $method, string $url, array $options) use ($pdf): Response {
        $request = (string)($options['body'] ?? '');
        $envelope = static fn(string $ns, string $inner) => '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="' . $ns . '">'
            . '<SOAP-ENV:Body><ns:READResponse>' . $inner . '</ns:READResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

        if (str_contains($request, 'xmlns:ns="/CREATEAWB"')) {
            return Response::xml(200, $envelope('/CREATEAWB', '<ns:st_flag>0</ns:st_flag><ns:st_title>OK</ns:st_title><ns:vg_code>WA123456789GR</ns:vg_code>'));
        }

        if (str_contains($request, 'xmlns:ns="/PELB64VG"')) {
            return Response::xml(200, $envelope('/PELB64VG', '<ns:st_flag>0</ns:st_flag><ns:st_title>OK</ns:st_title><ns:b64_string>' . $pdf('ELTA COURIER', 'WA123456789GR') . '</ns:b64_string>'));
        }

        if (str_contains($request, 'xmlns:ns="/PELTT01"')) {
            return Response::xml(200, $envelope('/PELTT01', '<ns:st_flag>0</ns:st_flag><ns:st_title></ns:st_title>'
                . '<ns:pod_date>02/10/2026</ns:pod_date><ns:pod_time>11:20</ns:pod_time><ns:pod_name>ΠΑΠΑΔΟΠΟΥΛΟΣ</ns:pod_name>'
                . '<ns:web_status_counter>3</ns:web_status_counter>'
                . '<ns:web_status><ns:web_date>01/10/2026</ns:web_date><ns:web_time>13:05</ns:web_time><ns:web_station>ΑΘΗΝΑ</ns:web_station><ns:web_status_title>ΠΑΡΑΛΑΒΗ ΑΠΟ ΑΠΟΣΤΟΛΕΑ</ns:web_status_title></ns:web_status>'
                . '<ns:web_status><ns:web_date>02/10/2026</ns:web_date><ns:web_time>07:30</ns:web_time><ns:web_station>ΑΘΗΝΑ</ns:web_station><ns:web_status_title>ΣΕ ΔΙΑΝΟΜΗ</ns:web_status_title></ns:web_status>'
                . '<ns:web_status><ns:web_date>02/10/2026</ns:web_date><ns:web_time>11:20</ns:web_time><ns:web_station>ΑΘΗΝΑ</ns:web_station><ns:web_status_title>ΠΑΡΑΔΟΘΗΚΕ</ns:web_status_title></ns:web_status>'));
        }

        return new Response(500, ['Content-Type' => ['text/xml']], '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body><SOAP-ENV:Fault><faultcode>SOAP-ENV:Server</faultcode><faultstring>No fixture</faultstring></SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>');
    },
];
