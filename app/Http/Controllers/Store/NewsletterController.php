<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The storefront's newsletter list.
 *
 * The form on the home page used to be a theatre: it checked the
 * address in the browser, waited 700ms and printed "YOU'RE IN ✓"
 * without sending anything. The shopper was told they had subscribed
 * and the store never learned their address.
 *
 * These two actions are the truth behind that form. `subscribe` writes
 * the address, `unsubscribe` takes it off the list, and the browser
 * only says the subscription happened once the server has said so.
 */
class NewsletterController extends Controller
{
    /**
     * Subscribe an address to the newsletter.
     *
     * The same address twice is not two subscribers: the row is found
     * and brought back to life if it had unsubscribed, and the caller
     * is told it was already on the list so the browser can say so
     * rather than pretending it was a fresh subscription.
     */
    public function subscribe(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $subscriber = NewsletterSubscriber::query()->where('email', $email)->first();

        if ($subscriber !== null && $subscriber->isActive()) {
            return $this->answer($request, [
                'success' => true,
                'message' => 'You are already on the list.',
                'already_subscribed' => true,
            ]);
        }

        if ($subscriber === null) {
            $subscriber = NewsletterSubscriber::query()->create([
                'email' => $email,
                'source' => 'storefront',
                'status' => NewsletterSubscriber::STATUS_SUBSCRIBED,
                'subscribed_at' => now(),
            ]);
        } else {
            // The same person came back after leaving: one row, one
            // history, subscribed again.
            $subscriber->update([
                'status' => NewsletterSubscriber::STATUS_SUBSCRIBED,
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ]);
        }

        return $this->answer($request, [
            'success' => true,
            'message' => "You're on the list.",
            'already_subscribed' => false,
        ]);
    }

    /**
     * Take an address off the list.
     *
     * The row is kept — with its status flipped — so the store knows the
     * address asked to leave, and so a later subscription is a change of
     * state rather than a second row.
     */
    public function unsubscribe(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $subscriber = NewsletterSubscriber::query()->where('email', $email)->first();

        if ($subscriber !== null && ! $subscriber->isActive()) {
            return $this->answer($request, [
                'success' => true,
                'message' => 'You are not on the list.',
                'was_subscribed' => false,
            ]);
        }

        if ($subscriber !== null) {
            $subscriber->update([
                'status' => NewsletterSubscriber::STATUS_UNSUBSCRIBED,
                'unsubscribed_at' => now(),
            ]);
        }

        return $this->answer($request, [
            'success' => true,
            'message' => 'You have been removed from the list.',
            'was_subscribed' => $subscriber !== null,
        ]);
    }

    /**
     * Answer the caller in the shape it asked for.
     *
     * The browser posts the form with fetch and reads JSON; a shopper
     * with JavaScript off posts the same form and is sent back to the
     * page they came from with a message in the session.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function answer(Request $request, array $payload): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return back()->with('status', $payload['message']);
    }
}
