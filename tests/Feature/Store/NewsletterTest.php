<?php

namespace Tests\Feature\Store;

use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The newsletter list.
 *
 * The home page's signup form used to be a theatre: the browser checked
 * the address, waited 700ms and printed "YOU'RE IN ✓" without sending
 * anything. The shopper was told they had subscribed and the store
 * never learned their address.
 *
 * These tests pin down the truth behind the form: the address is
 * written to the newsletter_subscribers table, the same address is one
 * subscriber and not two, and a request that fails is never dressed up
 * as a success.
 */
class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    // ─── The endpoint ────────────────────────────────────────────

    public function test_subscribing_stores_the_address(): void
    {
        $response = $this->postJson(route('newsletter.subscribe'), [
            'email' => 'shopper@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('already_subscribed', false);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'shopper@example.com',
            'status' => NewsletterSubscriber::STATUS_SUBSCRIBED,
        ]);

        $subscriber = NewsletterSubscriber::query()->firstOrFail();

        $this->assertNotNull($subscriber->subscribed_at);
        $this->assertTrue($subscriber->isActive());
    }

    public function test_the_same_address_is_one_subscriber_and_not_two(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => 'shopper@example.com']);
        $response = $this->postJson(route('newsletter.subscribe'), ['email' => 'shopper@example.com']);

        $response->assertOk();
        $response->assertJsonPath('already_subscribed', true);

        $this->assertSame(1, NewsletterSubscriber::query()->count());
    }

    public function test_the_address_is_stored_the_same_way_however_it_was_typed(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => 'Shopper@Example.com']);
        $this->postJson(route('newsletter.subscribe'), ['email' => '  shopper@example.com  ']);

        $this->assertSame(1, NewsletterSubscriber::query()->count());
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'shopper@example.com']);
    }

    public function test_a_person_who_left_the_list_can_come_back(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => 'shopper@example.com']);
        $this->postJson(route('newsletter.unsubscribe'), ['email' => 'shopper@example.com']);

        $this->assertSame(NewsletterSubscriber::STATUS_UNSUBSCRIBED, NewsletterSubscriber::query()->firstOrFail()->status);

        $this->postJson(route('newsletter.subscribe'), ['email' => 'shopper@example.com']);

        $this->assertSame(1, NewsletterSubscriber::query()->count());
        $this->assertSame(NewsletterSubscriber::STATUS_SUBSCRIBED, NewsletterSubscriber::query()->firstOrFail()->status);
    }

    public function test_an_invalid_address_is_refused_and_nothing_is_stored(): void
    {
        $response = $this->postJson(route('newsletter.subscribe'), ['email' => 'not-an-address']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');

        $this->assertSame(0, NewsletterSubscriber::query()->count());
    }

    public function test_an_empty_address_is_refused(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, NewsletterSubscriber::query()->count());
    }

    public function test_unsubscribing_takes_the_address_off_the_list(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => 'shopper@example.com']);

        $response = $this->postJson(route('newsletter.unsubscribe'), ['email' => 'shopper@example.com']);

        $response->assertOk();
        $response->assertJsonPath('was_subscribed', true);

        $this->assertSame(0, NewsletterSubscriber::query()->active()->count());
    }

    public function test_unsubscribing_an_address_that_never_signed_up_is_harmless(): void
    {
        $response = $this->postJson(route('newsletter.unsubscribe'), ['email' => 'stranger@example.com']);

        $response->assertOk();
        $response->assertJsonPath('was_subscribed', false);

        $this->assertSame(0, NewsletterSubscriber::query()->count());
    }

    public function test_the_endpoint_is_throttled(): void
    {
        // Five signups a minute: the sixth is refused.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('newsletter.subscribe'), ['email' => 'shopper'.$attempt.'@example.com'])
                ->assertOk();
        }

        $this->postJson(route('newsletter.subscribe'), ['email' => 'shopper6@example.com'])
            ->assertStatus(429);
    }

    public function test_a_plain_form_post_comes_back_to_the_page(): void
    {
        // The shopper with JavaScript off posts the same form.
        $response = $this->from(route('home'))->post(route('newsletter.subscribe'), [
            'email' => 'shopper@example.com',
        ]);

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'shopper@example.com']);
    }

    // ─── The form ─────────────────────────────────────────────────

    public function test_the_home_page_form_posts_to_the_subscribe_endpoint(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('action="'.route('newsletter.subscribe').'"', false);
        $response->assertSee('method="POST"', false);
        $response->assertSee('name="_token"', false);
    }
}
