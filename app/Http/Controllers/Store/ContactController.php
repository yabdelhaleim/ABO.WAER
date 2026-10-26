<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ContactMessageRequest;
use App\Http\Requests\Store\NewsletterRequest;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public contact endpoints: the newsletter sign-up and the /contact form.
 *
 * Both are reachable by guests — the storefront only asks for an email
 * address before the visitor has an account — so neither route sits
 * behind the `auth` middleware. Both answer with JSON because the
 * browser modules (`home.js`, `content.js`) submit them with `fetch`.
 */
class ContactController extends Controller
{
    /**
     * Store a newsletter subscription.
     *
     * `firstOrCreate` keeps the endpoint idempotent: a visitor who
     * submits the same address twice gets the same friendly answer
     * instead of a unique-constraint error. The `source` column records
     * where the sign-up came from so the store can tell a footer
     * subscriber from a checkout one.
     */
    public function subscribe(NewsletterRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->validated('email')));

        $subscriber = NewsletterSubscriber::firstOrCreate(
            ['email' => $email],
            ['source' => $this->source($request)],
        );

        return response()->json([
            'success' => true,
            'message' => $subscriber->wasRecentlyCreated
                ? 'You are on the list. Welcome aboard.'
                : 'You are already on the list.',
            'subscribed' => true,
        ], $subscriber->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Store a message sent through the /contact page.
     */
    public function message(ContactMessageRequest $request): JsonResponse
    {
        $data = $request->validated();

        $message = ContactMessage::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'subject' => $data['topic'] ?? null,
            'message' => $data['message'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent. We will reply within 24 hours.',
            'id' => $message->id,
        ], 201);
    }

    /**
     * Where the subscription came from: the referring page when the
     * browser sends one, otherwise the route the form was submitted to.
     */
    protected function source(Request $request): string
    {
        $referer = $request->headers->get('referer');

        if (is_string($referer) && $referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);

            if (is_string($path) && $path !== '') {
                return mb_substr($path, 0, 150);
            }
        }

        return mb_substr($request->path(), 0, 150);
    }
}
