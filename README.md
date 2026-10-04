# Carrier for National Posts

Four south-east European national posts for [Carrier](https://justinholt.com/plugins/craft-carrier), the shipping-carrier gateway for Craft Commerce 5:

| Carrier | Handle | Country |
|---|---|---|
| Hrvatska pošta (Paket 24) | `posta-hr` | Croatia |
| Magyar Posta (MPL) | `posta-hu` | Hungary |
| Pošta Slovenije | `posta-si` | Slovenia |
| ELTA Courier | `elta` | Greece |

This package only translates each post's API. Carrier handles everything else: packing, checkout, label storage, the guard that stops a label being bought twice, tracking, the pickup-point picker and the request log.

## Requirements

- Craft CMS 5.3+ and Craft Commerce 5
- Carrier 5.0+
- A business contract with each post you use. None of these APIs are open to the public, and every post issues its own credentials.

## Installation

```sh
composer require justinholtweb/craft-carrier-posts
php craft plugin/install carrier-posts
```

Then go to **Carrier → Connections → New connection** and pick the post.

## What each post can do

| | Hrvatska pošta | Magyar Posta | Pošta Slovenije | ELTA Courier |
|---|---|---|---|---|
| Labels | PDF (A6, A4 four-up) | PDF (A6, A6 four-up on A4), ZPL | **no** | PDF (A6, A4) |
| Cash on delivery | EUR | HUF (whole forints) | — | EUR |
| Multi-piece | yes | yes (up to 16) | — | no |
| Void | yes | until the day is closed | — | **no** (call your branch) |
| Reprint | yes | until the day is closed | — | yes |
| End-of-day close | — | **required** | — | — |
| Tracking | batch of 20 | one per call | batch of 10 | one per call |
| Pickup points | post offices + Paketomats (daily sync) | post offices, PostaPont, parcel terminals (daily sync) | opt-in, unofficial | — |
| Live rates | no | no | no | no |
| Sandbox | yes | yes (a mock) | — | test account |
| **Confidence** | **High** | **High** | **Low–medium** | **Low** |

None of the four posts has a rate API, so shipping methods price from a table built from your contract.

Confidence means how much of the driver is checked against the post's own published documentation. Nothing in this package has been run against a live account. Every assumption that could not be checked against a primary source is marked **UNVERIFIED** in that carrier's class docblock (`src/carriers/*.php`), and the main ones are summarised below.

## Hrvatska pošta

**Credentials:** the API username and password from your Hrvatska pošta contract manager. Test (`dxwebapit.posta.hr`) and production (`dxwebapi.posta.hr`) have separate credentials.

**Ports 9000 and 9020.** Sign-in uses port 9000 and everything else uses 9020. Shared hosting often blocks outbound traffic to ports other than 443. If the connection test times out, ask your host to allow `dxwebapi.posta.hr` on both ports.

**Services:** Paket 24 D+1, D+2, D+3 and D+4 to the door, plus D+1 to a Paketomat or to a post office. Point services need the customer to choose a point at checkout.

**Paketomat compartments.** Hrvatska pošta needs a compartment size (X, S, M or L). Carrier picks the smallest one that fits the parcel's dimensions. For a parcel without dimensions it uses the default you set on the connection. You can force a size on a shipment with the request option `parcelSize`.

**E-mail addresses are capped at 25 characters** by Hrvatska pošta. A longer address is left off the shipment, and the shipment shows a warning, because cutting an address short would make it wrong. Names, streets and cities are trimmed to the API's limits.

**Cancelling** works by client reference. Carrier sends the order reference plus a short time stamp, because Hrvatska pošta needs the reference to be unique and one order can have several shipments.

UNVERIFIED: the page size of the default label (taken to be A6), the Paketomat compartment dimensions, that a post office's point id is its postcode, and how long after creation a shipment can still be cancelled.

## Magyar Posta (MPL)

**Credentials:** an application's key and secret from [devportal.posta.hu](https://devportal.posta.hu) (sandbox and production keys are separate), your customer code (sent as `X-Accounting-Code`) and your agreement code. If you collect cash on delivery and have it paid by bank transfer, also enter the bank account.

**Close the day.** MPL dispatches nothing until the day's shipments are closed. Use **Close the day** under **Carrier → Collections** once a day for each connection, after the last label. The close returns the posting list (a PDF) and indicative prices in HUF, which are stored with the close. Once a shipment is closed, **it disappears from MPL's shipping API**: you can no longer void or reprint it, though tracking still works. So void and reprint before you close.

**Parcel terminals:** one parcel each, at most 20 kg and at most 500,000 HUF cash on delivery. The compartment size (S, M or L) is chosen from the parcel's dimensions. A Postal Parcel takes one parcel, up to 10 kg.

**ZPL** labels are A6. MPL allows ZPL only on A6-type labels.

**Multi-parcel consignments:** the first tracking number is the consignment number, which is the number MPL tracks. The other parcels get their piece barcodes.

UNVERIFIED: the sandbox token URL, how the label reprint call encodes several tracking numbers, the response to a successful void, the pickup-point fields beyond id, name, address and coordinates, and the public tracking link.

## Pošta Slovenije

**Pošta Slovenije does not buy labels through Carrier.** Here is why.

Pošta Slovenije's shipment API (eSpremnica "eOddaja") receives shipment data. It does not return a tracking number or a label:

- **Barcodes:** you number the parcels yourself, from a barcode range Pošta Slovenije assigns to you.
- **Labels:** you print them yourself, to a layout specification that comes with the contract and is not public.
- **Errors:** the data is checked asynchronously, and any errors come back 15 to 30 seconds after you submit it.
- **Shipment codes:** the codes that define a shipment (parcel type, cash on delivery, Paketomat delivery) are issued with each contract and are not published.

Carrier only reports a label as bought when it holds a real tracking number and a label the post will accept. That is not possible from public information: a home-made label that looks right but is refused at the counter is worse than no label. So this connection does not offer labels, voids, reprints or cash on delivery.

Create your Pošta Slovenije shipments in eSpremnica or the ePortal as you do now. Then attach each barcode to its order with **Shipped some other way? Record its tracking number** on the order's shipment screen (**Carrier → Shipments → the order**), choosing the Pošta Slovenije connection. From then on Carrier tracks the parcel and writes its status back to the order like any other shipment.

**Tracking credentials:** a user and a password (both GUIDs) and a user token. They come from your contract manager and are separate from your eSpremnica login. This uses the tracking API that replaced the old service on 1 October 2025. It covers the last 60 days, ten barcodes per request.

**Pickup points** are off by default. Pošta Slovenije has no public pickup-point API. If you switch on **Use the unofficial posta.si point finder**, Carrier searches by postcode with the JSON that the posta.si office finder uses. It is not a supported interface and may change or stop working without notice.

UNVERIFIED: the structure of the tracking response (Pošta Slovenije has not published it; Carrier reads the field names of the old service), the status wording (mapped by Slovenian keyword), and whether the public tracking link works without the per-contract `guid` parameter.

## ELTA Courier

> **Verify before production.** ELTA Courier publishes no API documentation. This driver is based on public third-party integrations and needs checking against a live account and the WSDLs in your contract bundle before you ship real parcels with it.

> **Plain HTTP.** The web services run at `http://212.205.47.226:9003`, which is unencrypted HTTP on a bare IP address. Your user code, password and customer code cross the internet in clear text on every request. Your host must also allow outbound connections to port 9003.

**Credentials:** user code (digits only), password, customer code and, if you have one, a sub-code. Ask your branch, phone +30 210 607 3000 or e-mail info@elta-courier.gr. The test account is `9999999` / `9999999` / customer `999999999`; it does not support cash on delivery.

**No voids.** ELTA Courier's web services cannot cancel a voucher. Tell your local branch.

**No pickup points.** ELTA has a store-by-postcode lookup, but its response is undocumented and nothing in the voucher call sends a parcel to a store.

**One parcel per voucher.** ELTA can put several pieces under one voucher, but the format of the child vouchers it returns is unknown, so this driver does not declare multi-piece. Carrier refuses a shipment with more than one parcel for ELTA and asks for it to be split into one shipment per parcel.

**Buying a label takes two calls:** one creates the voucher and one fetches the PDF. If the voucher is created but the PDF cannot be fetched, the shipment is marked *uncertain* and the message gives the voucher number. Don't buy it again: record the voucher as created and reprint it.

UNVERIFIED: the voucher call's response fields (`st_flag`, `st_title`, `vg_code`), the label call's PDF element (`b64_string`), the SOAP endpoint path and `SOAPAction` (both editable on the connection), number formats and field lengths, and the Greek tracking wording. Tracking titles that do not match a known phrase are written to the connection log so the mapping can be extended.

## Known limits of the gateway (for the Carrier maintainer)

- Pošta Slovenije's point finder is on a different host from its tracking API, but Carrier's transport adds the tracking token to every request. The driver pauses its own sign-in around that call. A per-request "no auth" option on `Transport::request()` would be cleaner.
- Pošta Slovenije barcodes are recorded by hand on the shipment screen; Carrier does not create them.
- The conformance suite fills boolean settings with their default, so it can't run the Pošta Slovenije point search, which is opt-in. That one check fails by design. It passes with the setting on.

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Carrier for National Posts is free: no editions, no licence key, and no licensing code in the plugin. It needs [Carrier](https://justinholt.com/plugins/craft-carrier), which is commercial.
