{{-- resources/views/emails/campaign.blade.php --}}
{{--
    Branded marketing email sent by App\Notifications\CampaignNotification.

    Designed to render identically across Gmail, Apple Mail and Outlook:
      - Tables-based layout (Outlook ignores <div> grids).
      - Inline styles only (Gmail strips most <style> tags).
      - Pixel tracking image at the bottom of the body.
      - CTA link rewritten through the click tracker.

    Available variables:
      $campaign — the App\Models\Campaign being delivered.
      $customer — the notifiable Customer (used to greet them by name).
--}}
@php
    /** @var \App\Models\Campaign $campaign */
    /** @var \App\Models\Customer $customer */
    $customerName = $customer?->name ?? 'there';
    $subjectLine = $campaign->subject;
    $previewLine = $campaign->preview;
    $messageBody = $campaign->message;
    $ctaUrl = route('campaigns.track.click', $campaign->id) . '?url=' . urlencode(route('shop'));
    $openPixelUrl = route('campaigns.track.open', $campaign->id);
    $unsubscribeUrl = route('campaigns.track.click', $campaign->id) . '?url=' . urlencode(route('account'));
    $storeName = config('app.name', 'ABO Store');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $subjectLine }}</title>
</head>
<body
    style="margin:0;padding:0;background-color:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;-webkit-text-size-adjust:100%;">
{{-- Preheader: shown in the inbox preview pane before the body opens. --}}
<span style="display:none;font-size:1px;color:#f5f5f7;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
    {{ $previewLine }}
</span>
<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
       style="background-color:#f5f5f7;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"
                   style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.06);">
                {{-- Brand header: ABO Store logo lockup. --}}
                <tr>
                    <td align="center"
                        style="background-color:#1a1a1a;padding:24px 32px;font-family:Georgia,'Times New Roman',serif;">
                        <span style="display:inline-block;color:#ffffff;font-size:24px;font-weight:700;letter-spacing:4px;">
                            ABO
                        </span>
                        <span style="display:inline-block;color:#d4af37;font-size:24px;font-weight:700;letter-spacing:4px;margin-left:4px;">
                            STORE
                        </span>
                    </td>
                </tr>
                {{-- Subject line. --}}
                <tr>
                    <td style="padding:32px 32px 8px 32px;">
                        <h1 style="margin:0;font-size:24px;line-height:1.3;color:#1a1a1a;font-weight:700;">
                            {{ $subjectLine }}
                        </h1>
                    </td>
                </tr>
                {{-- Greeting + body copy. --}}
                <tr>
                    <td style="padding:8px 32px 0 32px;font-size:16px;line-height:1.7;color:#333333;">
                        <p style="margin:0 0 16px 0;">
                            Hi {{ $customerName }},
                        </p>
                        <p style="margin:0 0 24px 0;white-space:pre-line;">
                            {{ $messageBody }}
                        </p>
                    </td>
                </tr>
                {{-- CTA button. --}}
                <tr>
                    <td align="center" style="padding:16px 32px 32px 32px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="center" bgcolor="#1a1a1a" style="border-radius:8px;">
                                    <a href="{{ $ctaUrl }}" target="_blank" rel="noopener"
                                       style="display:inline-block;background-color:#1a1a1a;color:#ffffff;font-size:16px;font-weight:600;text-decoration:none;padding:14px 36px;border-radius:8px;letter-spacing:0.5px;">
                                        Browse the offer
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                {{-- Secondary preview block. --}}
                <tr>
                    <td style="padding:0 32px 24px 32px;font-size:14px;line-height:1.6;color:#666666;text-align:center;">
                        {{ $previewLine }}
                    </td>
                </tr>
                {{-- Footer: brand line, store contact, unsubscribe link. --}}
                <tr>
                    <td style="background-color:#f5f5f7;padding:24px 32px;text-align:center;font-size:12px;line-height:1.6;color:#999999;">
                        <p style="margin:0 0 8px 0;color:#1a1a1a;font-weight:600;">
                            {{ $storeName }} &middot; Men's Fashion
                        </p>
                        <p style="margin:0 0 8px 0;">
                            You're receiving this email because you signed up to {{ $storeName }} updates.
                        </p>
                        <p style="margin:0;">
                            <a href="{{ $unsubscribeUrl }}" target="_blank" rel="noopener"
                               style="color:#666666;text-decoration:underline;">
                                Manage email preferences
                            </a>
                            &nbsp;&middot;&nbsp;
                            <span>&copy; {{ date('Y') }} {{ $storeName }}. All rights reserved.</span>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
{{-- Tracking pixel: 1x1 transparent GIF that pings the open tracker. --}}
<img src="{{ $openPixelUrl }}" width="1" height="1" alt="" border="0"
     style="display:block;border:0;outline:none;text-decoration:none;width:1px;height:1px;">
</body>
</html>
