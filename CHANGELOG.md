# Release Notes for Carrier for National Posts

## 5.0.0 - 2026-10-03

### Added
- Hrvatska pošta (`posta-hr`): Paket 24 labels (D+1 to D+4) to the door, a post office or a Paketomat, cash on delivery, multi-piece, void by client reference, reprint (single or A4 four-up), batch tracking and a daily-synced list of post offices and Paketomats.
- Magyar Posta (`posta-hu`): MPL Business and Postal Parcels to the door, a post office, PostaPont or a parcel terminal, cash on delivery in forints, multi-piece, PDF or ZPL labels, void and reprint before close, the mandatory end-of-day close with its posting list, tracking and a daily-synced point list.
- Pošta Slovenije (`posta-si`): tracking through the Azure tracking API, and an opt-in pickup-point search using the unofficial posta.si office finder. No labels: see the README for why.
- ELTA Courier (`elta`): vouchers with cash on delivery, PDF labels (A4 or A6), reprint and tracking through ELTA Courier's contract SOAP services. Low confidence: verify against a live account before production.
