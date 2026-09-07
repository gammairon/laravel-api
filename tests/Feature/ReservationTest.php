<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(string $reference = 'web-order-9f782b1c'): array
    {
        return [
            'client_reference' => $reference,
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ];
    }

    public function test_it_books_a_unit_of_an_offer(): void
    {
        $offer = Offer::factory()->create(['available_units' => 2, 'price' => 72500]);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.offer_id', $offer->id)
            ->assertJsonPath('data.client_reference', 'web-order-9f782b1c')
            ->assertJsonPath('data.customer_name', 'John Smith')
            ->assertJsonPath('data.customer_email', 'john@example.com')
            ->assertJsonPath('data.units', 1)
            // The price is snapshotted from the offer.
            ->assertJsonPath('data.price', 72500)
            ->assertJsonPath('data.currency', 'EUR');

        $this->assertSame(1, $offer->refresh()->available_units);
        $this->assertSame(1, Reservation::count());
    }

    public function test_the_second_booking_of_the_last_unit_is_rejected(): void
    {
        $offer = Offer::factory()->create(['available_units' => 1]);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload('order-1'))
            ->assertCreated();

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload('order-2'))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'sold_out');

        $this->assertSame(0, $offer->refresh()->available_units);
        $this->assertSame(1, Reservation::count());
    }

    public function test_it_rejects_a_sold_out_offer(): void
    {
        $offer = Offer::factory()->soldOut()->create();

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'sold_out');

        $this->assertSame(0, Reservation::count());
    }

    public function test_it_rejects_an_expired_offer(): void
    {
        $offer = Offer::factory()->expired()->create(['available_units' => 5]);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'expired');

        $this->assertSame(5, $offer->refresh()->available_units);
        $this->assertSame(0, Reservation::count());
    }

    public function test_a_repeated_client_reference_never_books_twice(): void
    {
        $offer = Offer::factory()->create(['available_units' => 5]);

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertCreated();

        $this->postJson("/api/offers/{$offer->id}/reservations", $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'duplicate_client_reference');

        // The retried request consumed no unit: the transaction rolled back.
        $this->assertSame(4, $offer->refresh()->available_units);
        $this->assertSame(1, Reservation::count());
    }

    public function test_it_validates_the_request_body(): void
    {
        $offer = Offer::factory()->create();

        $this->postJson("/api/offers/{$offer->id}/reservations", ['customer_email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_reference', 'customer_name', 'customer_email']);
    }

    public function test_it_returns_404_for_an_unknown_offer(): void
    {
        $this->postJson('/api/offers/999999/reservations', $this->payload())
            ->assertNotFound();
    }
}
