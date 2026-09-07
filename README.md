# Housing Offers API

A REST API that imports accommodation offers from suppliers asynchronously, exposes the cheapest
bookable offer per property, and lets a client reserve one safely.

- **PHP** 8.2+ (developed on 8.4)
- **Laravel** 12
- **MySQL** 8+
- **Queue** `database` (switch `QUEUE_CONNECTION` to `redis` if you have Redis)

---

## Installation

```bash
git clone <repository-url>
cd <project>

composer install

cp .env.example .env
php artisan key:generate
```

Create the database and point `.env` at it:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=test
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
```

```sql
CREATE DATABASE test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Migrations and seeders

```bash
php artisan migrate            # schema
php artisan db:seed            # suppliers: supplier-a, supplier-b
# or both at once:
php artisan migrate --seed
```

### Queue worker

Imports are processed by a queued job, so a worker must be running:

```bash
php artisan queue:work
# process whatever is queued and exit (handy in scripts / CI):
php artisan queue:work --stop-when-empty
```

### Tests

The feature tests run against MySQL rather than SQLite, because the search relies on window functions
and the booking flow on `SELECT ... FOR UPDATE` — the two things worth verifying on the engine that
actually ships.

They use their own database. `RefreshDatabase` starts a run with `migrate:fresh`, which drops every
table in whatever database it is pointed at, so it must never share one with the application. Create it
once:

```sql
CREATE DATABASE test_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`phpunit.xml` already points at `test_testing` and reuses the host and credentials from `.env`. There is
nothing to migrate by hand — the suite builds the schema itself, and the application database is left
untouched.

```bash
php artisan test
# or
./vendor/bin/phpunit
```

### Serving locally

```bash
php artisan serve
```

---

## Endpoints

### `POST /api/imports` → `202 Accepted`

Validates the batch, records it, queues the processing and returns immediately.

```json
{
  "supplier": "supplier-a",
  "external_import_id": "import-2026-09-01-001",
  "sent_at": "2026-09-01T10:00:00Z",
  "offers": [
    {
      "external_id": "offer-a-10001",
      "property": { "code": "BCN-0001", "name": "Apartment near Sagrada Familia", "city": "Barcelona" },
      "check_in": "2026-10-10",
      "check_out": "2026-10-15",
      "max_guests": 4,
      "price": 72500,
      "currency": "EUR",
      "available_units": 2,
      "expires_at": "2026-09-10T23:59:59Z"
    }
  ]
}
```

```json
{ "data": { "id": 15, "status": "pending" } }
```

### `GET /api/imports/{import}` → `200 OK`

```json
{
  "data": {
    "id": 15,
    "supplier": "supplier-a",
    "external_import_id": "import-2026-09-01-001",
    "sent_at": "2026-09-01T10:00:00Z",
    "status": "completed",
    "total_offers": 20,
    "processed_offers": 20,
    "error": null,
    "created_at": "2026-09-01T10:00:02Z",
    "completed_at": "2026-09-01T10:00:04Z"
  }
}
```

`status` is one of `pending`, `processing`, `completed`, `failed`. On failure `error` carries the
exception message and `completed_at` stays `null`.

### `GET /api/properties` → `200 OK`

```
GET /api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&page=1&per_page=15
```

An offer is eligible when `check_in` / `check_out` match the query exactly, `max_guests >= guests`,
`available_units > 0`, `expires_at > now()`, and — when `city` is given — the property is in that city.
Properties are ordered by their best price.

```json
{
  "data": [
    {
      "code": "BCN-0001",
      "name": "Apartment near Sagrada Familia",
      "city": "Barcelona",
      "best_offer": {
        "id": 125,
        "supplier": "supplier-a",
        "price": 72500,
        "currency": "EUR",
        "available_units": 2,
        "expires_at": "2026-09-10T23:59:59Z"
      }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3, "...": "..." }
}
```

### `POST /api/offers/{offer}/reservations` → `201 Created`

```json
{
  "client_reference": "web-order-9f782b1c",
  "customer_name": "John Smith",
  "customer_email": "john@example.com"
}
```

```json
{
  "data": {
    "id": 1,
    "offer_id": 125,
    "client_reference": "web-order-9f782b1c",
    "customer_name": "John Smith",
    "customer_email": "john@example.com",
    "units": 1,
    "price": 72500,
    "currency": "EUR",
    "created_at": "2026-09-07T19:44:41Z"
  }
}
```

Returns `409 Conflict` with a machine-readable `reason` (`sold_out`, `expired`,
`duplicate_client_reference`), `422` on validation errors and `404` for an unknown offer.

---

## Import idempotency

Three things make a resent batch a no-op instead of a duplicate:

1. **`imports` has a unique index on `(supplier_id, external_import_id)`.** `ImportService::submit()`
   looks the pair up first and returns the existing import; if two identical requests race each other,
   the index rejects the loser's insert and the caught `UniqueConstraintViolationException` re-reads
   the winner's row.
2. **The job is dispatched only on insert.** A repeated submission returns `202` with the same import
   id and queues nothing, so processing never runs twice for one batch. `ProcessImportJob` also
   implements `ShouldBeUnique`, and it returns early if the import is already `completed`.
3. **Offers are upserted, not appended.** `offers` has a unique index on `(supplier_id, external_id)`
   and the job uses `updateOrCreate()` on that pair, so an offer that reappears in a later import is
   updated in place. Properties are matched by `property.code` with `firstOrCreate()`.

The whole batch is applied inside one `DB::transaction()`: either every offer lands and the import
becomes `completed`, or nothing is written and it becomes `failed` with the error recorded. Because
the write is idempotent, replaying a failed import is always safe.

## Concurrency and double-booking protection

`ReservationService::reserve()` runs inside `DB::transaction()` and re-reads the offer with
`lockForUpdate()`, which issues `SELECT ... FOR UPDATE` on that single row:

```php
DB::transaction(function () use ($offer, $data) {
    $locked = Offer::whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

    if ($locked->expires_at->isPast()) { throw OfferNotBookableException::expired(); }
    if ($locked->available_units < 1)  { throw OfferNotBookableException::soldOut(); }

    $locked->decrement('available_units');

    return Reservation::create([...]);
});
```

Two requests competing for the last unit are serialised by InnoDB: the first holds the row lock until
it commits, the second blocks on the same row and, once it wakes up, reads `available_units = 0` and
fails with `409 sold_out`. The check and the decrement can never interleave, so the count cannot go
below zero.

Two further guards back this up at the schema level:

- `offers.available_units` is `UNSIGNED` — an over-decrement is rejected by MySQL rather than silently
  wrapping into a negative stock.
- `reservations.client_reference` is `UNIQUE` — a retried or duplicated client request cannot create a
  second reservation. The insert fails, the surrounding transaction rolls back, and the unit that was
  about to be consumed is released.

A dedicated multi-process test is not included (per the task description), but
`ReservationTest::test_the_second_booking_of_the_last_unit_is_rejected` and
`test_a_repeated_client_reference_never_books_twice` cover the outcome sequentially.

---

## Design notes

- **Money is stored as an integer in minor units** (`72500` = 725.00 EUR) so prices never touch a
  float. Comparisons assume the offers being compared share a currency; multi-currency ranking would
  need an FX table and is out of scope.
- **The cheapest offer is chosen in SQL.** `PropertySearchService` numbers the matching offers per
  property with `ROW_NUMBER() OVER (PARTITION BY property_id ORDER BY price, id)` and joins back only
  row 1. Filtering, ordering and pagination all happen in the database — no collection grouping in PHP.
- **Indexes.** `offers_search_index (check_in, check_out, max_guests, expires_at)` serves the
  availability filter, `offers_cheapest_index (property_id, check_in, check_out, price)` the
  per-property ranking, and `properties.city` the city filter. `EXPLAIN` shows the derived table using
  `offers_search_index` as a range scan.
- **Dates match exactly.** The spec says the dates must match the search parameters, so
  `check_in` / `check_out` are compared for equality rather than treated as an overlapping range.
- **The raw offers payload is stored on the import** (`imports.payload`). The job only needs an import
  id, which keeps the queue message small and lets a job be replayed without the original HTTP request.
- **Reservations snapshot price and currency**, because the same offer can be re-imported later with a
  different price.
- Business logic lives in `app/Services` and `app/Jobs`; controllers only validate (Form Requests),
  delegate, and shape the response (API Resources).

## Project layout

```
app/
├── Enums/ImportStatus.php
├── Exceptions/OfferNotBookableException.php      # rendered as 409
├── Http/
│   ├── Controllers/Api/{Import,Property,Reservation}Controller.php
│   ├── Requests/{StoreImport,SearchProperties,StoreReservation}Request.php
│   └── Resources/{Import,ImportSubmission,Property,Reservation}Resource.php
├── Jobs/ProcessImportJob.php                     # queued batch processing
├── Models/{Supplier,Property,Import,Offer,Reservation}.php
└── Services/{Import,PropertySearch,Reservation}Service.php

database/
├── factories/     # one per model
├── migrations/    # suppliers, properties, imports, offers, reservations
└── seeders/SupplierSeeder.php

tests/Feature/
├── ImportSubmissionTest.php    ImportStatusTest.php
├── ProcessImportJobTest.php    PropertySearchTest.php
└── ReservationTest.php
```
