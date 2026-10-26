<?php

namespace Tests\Feature\Store;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The branded error pages the storefront falls back on, and the JSON the
 * checkout endpoint answers with when the session behind the form is
 * gone.
 *
 * Nothing in the storefront raises 401, 419 or 429 on demand, so the
 * pages are exercised the way the framework resolves them: a throwaway
 * route that aborts with the status, hit over HTTP.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Register a throwaway route that aborts with $status, hit it, and
     * return the response the customer would see.
     */
    protected function abortWith(int $status): TestResponse
    {
        $uri = '/__test-abort-'.$status;

        Route::middleware('web')->get($uri, fn () => abort($status));

        return $this->get($uri);
    }

    /**
     * Assert the branded error page came back — the ABO layout, not
     * Laravel's default error screen.
     */
    protected function assertErrorPageRenders(int $status, string $expectedText): TestResponse
    {
        $response = $this->abortWith($status);

        $response->assertStatus($status);
        // escape: false — the Arabic copy is literal HTML in the
        // template, so the expectation must not be run through e().
        $response->assertSee($expectedText, false);
        $response->assertSee('abo-store-body', false);
        $response->assertDontSee('Whoops, looks like something went wrong', false);

        return $response;
    }

    public function test_expired_session_renders_the_custom_419_page(): void
    {
        $this->assertErrorPageRenders(419, 'انتهت صلاحية الجلسة');
    }

    public function test_expired_session_page_explains_itself_in_english_too(): void
    {
        $this->assertErrorPageRenders(419, 'Your session expired');
    }

    public function test_expired_session_page_sends_the_customer_back_to_the_cart_and_the_checkout(): void
    {
        $response = $this->assertErrorPageRenders(419, 'Your cart is still in the browser');

        $response->assertSee(route('cart'), false);
        $response->assertSee(route('checkout.show'), false);
    }

    public function test_rate_limited_request_renders_the_custom_429_page(): void
    {
        $this->assertErrorPageRenders(429, 'محاولات كتير في وقت قصير');
        $this->assertErrorPageRenders(429, 'Too many attempts in a row.');
    }

    public function test_unauthenticated_request_renders_the_custom_401_page(): void
    {
        $this->assertErrorPageRenders(401, 'سجّل دخولك عشان تفتح الصفحة دي');
        $this->assertErrorPageRenders(401, route('login'));
    }

    public function test_checkout_answers_an_expired_session_with_a_json_message(): void
    {
        // The CSRF middleware skips its check while the suite runs, so
        // the application is moved out of the "testing" environment for
        // this request only: the real check runs, the token does not
        // match the session, and the endpoint answers exactly the way it
        // answers a shopper whose session expired.
        $this->app['env'] = 'local';

        $response = $this->postJson(route('checkout.process'), [
            'name' => 'Omar Khalid',
            'phone' => '01012345678',
            'governorate' => 'Cairo',
            'area' => 'Nasr City',
            'address' => 'Abbas El Akkad St',
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(419);
        // The shape checkout.js reads: a JSON envelope with a message,
        // never an HTML error page.
        $response->assertJsonStructure(['message']);
        $this->assertIsString($response->json('message'));
    }

    public function test_checkout_page_carries_the_hook_for_a_submit_error_message(): void
    {
        // The 419/429 message is written next to the submit button, so
        // the button's wrapper is the hook the script looks for.
        $response = $this->get(route('checkout.show'));

        $response->assertOk();
        $response->assertSee('data-checkout-submit-wrap', false);
    }
}
