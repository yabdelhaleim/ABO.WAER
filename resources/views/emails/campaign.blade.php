{{-- resources/views/emails/campaign.blade.php --}}
@php
    /** @var \App\Models\Campaign $campaign */
    /** @var \App\Models\Customer $customer */
    /** @var string|null $coupon */
    $customerName = $customer?->name ?? 'there';
    $subjectLine = $campaign->subject;
    $previewLine = $campaign->preview;
    $messageBody = $campaign->message;
    $ctaUrl = route('campaigns.track.click', $campaign->id) . '?url=' . urlencode(route('shop'));
    $openPixelUrl = route('campaigns.track.open', $campaign->id);
    $unsubscribeUrl = route('campaigns.track.click', $campaign->id) . '?url=' . urlencode(route('account'));
    $storeName = config('app.name', 'ABO Store');
    $logoUrl = asset('images/abo-icon-192.png');
    $bannerImage = $campaign->image_url;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="dark only">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background-color:#08080a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#ffffff;-webkit-text-size-adjust:100%;">
{{-- Preheader: visible in inbox snippet, invisible inside email body --}}
<span style="display:none;font-size:1px;color:#08080a;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
    {{ $previewLine ?: $messageBody }}
</span>

<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#08080a;padding:40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background-color:#121316;border-radius:16px;overflow:hidden;border:1px solid #222328;box-shadow:0 12px 40px rgba(0,0,0,0.6);">
                
                {{-- ─── 1. Brand Header ──────────────────────────────── --}}
                <tr>
                    <td align="center" style="background-color:#0d0e11;padding:28px 32px;border-bottom:1px solid #1f2026;">
                        <a href="{{ $ctaUrl }}" target="_blank" rel="noopener" style="text-decoration:none;display:inline-flex;align-items:center;gap:12px;">
                            <img src="{{ $logoUrl }}" alt="ABO" width="40" height="40" style="display:inline-block;border-radius:50%;vertical-align:middle;border:1px solid #333;">
                            <span style="display:inline-block;color:#ffffff;font-size:22px;font-weight:900;letter-spacing:5px;font-family:'Arial Black',Impact,sans-serif;vertical-align:middle;margin-left:8px;">
                                ABO <span style="color:#dff24a;font-size:16px;font-weight:700;letter-spacing:3px;">STORE</span>
                            </span>
                        </a>
                    </td>
                </tr>

                {{-- ─── 2. Campaign Banner Image ────────────────────── --}}
                @if (!empty($bannerImage))
                <tr>
                    <td align="center" style="padding:0;background-color:#000000;line-height:0;">
                        <a href="{{ $ctaUrl }}" target="_blank" rel="noopener" style="display:block;text-decoration:none;">
                            <img src="{{ $bannerImage }}" alt="{{ $subjectLine }}" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;outline:none;text-decoration:none;">
                        </a>
                    </td>
                </tr>
                @endif

                {{-- ─── 3. Main Message Content ─────────────────────── --}}
                <tr>
                    <td style="padding:36px 36px 20px 36px;">
                        {{-- Tag / Label --}}
                        <div style="display:inline-block;padding:4px 12px;background:rgba(223,242,74,0.12);border:1px solid rgba(223,242,74,0.3);border-radius:20px;color:#dff24a;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">
                            Official Update
                        </div>

                        {{-- Subject Headline --}}
                        <h1 style="margin:0 0 16px 0;font-size:26px;line-height:1.3;color:#ffffff;font-weight:800;letter-spacing:-0.5px;">
                            {{ $subjectLine }}
                        </h1>

                        {{-- Greeting --}}
                        <p style="margin:0 0 14px 0;font-size:16px;color:#a0a0a8;line-height:1.6;">
                            Hi <strong style="color:#ffffff;">{{ $customerName }}</strong>,
                        </p>

                        {{-- Message Body --}}
                        <p style="margin:0 0 28px 0;font-size:16px;line-height:1.7;color:#d0d0d8;white-space:pre-line;">
                            {{ $messageBody }}
                        </p>
                    </td>
                </tr>

                {{-- ─── 4. Coupon Ticket Block (If applicable) ───────── --}}
                @if (!empty($coupon))
                <tr>
                    <td align="center" style="padding:0 36px 28px 36px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#17191e;border:1px dashed #dff24a;border-radius:12px;padding:24px;">
                            <tr>
                                <td align="center">
                                    <span style="font-size:11px;color:#8f9099;letter-spacing:3px;text-transform:uppercase;font-weight:700;display:block;margin-bottom:8px;">
                                        YOUR EXCLUSIVE DISCOUNT CODE
                                    </span>
                                    <div style="display:inline-block;padding:8px 24px;background:#0d0e11;border:1px solid #333;border-radius:8px;font-size:26px;font-weight:900;color:#dff24a;letter-spacing:6px;font-family:Courier,monospace;">
                                        {{ $coupon }}
                                    </div>
                                    <span style="font-size:12px;color:#7e808a;display:block;margin-top:10px;">
                                        Use this code at checkout to claim your offer.
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @endif

                {{-- ─── 5. High-Impact CTA Button ────────────────────── --}}
                <tr>
                    <td align="center" style="padding:0 36px 40px 36px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="center" bgcolor="#dff24a" style="border-radius:8px;box-shadow:0 4px 15px rgba(223,242,74,0.25);">
                                    <a href="{{ $ctaUrl }}" target="_blank" rel="noopener" style="display:inline-block;background-color:#dff24a;color:#0a0a0b;font-size:15px;font-weight:900;text-decoration:none;padding:16px 44px;border-radius:8px;letter-spacing:1px;text-transform:uppercase;">
                                        Shop The Collection ➔
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ─── 6. Luxury Footer ─────────────────────────────── --}}
                <tr>
                    <td style="background-color:#0a0a0c;padding:32px 36px;border-top:1px solid #1a1b20;text-align:center;">
                        <p style="margin:0 0 10px 0;font-size:13px;font-weight:800;letter-spacing:2px;color:#ffffff;text-transform:uppercase;">
                            {{ $storeName }} &middot; PREMIUM STREETWEAR
                        </p>
                        <p style="margin:0 0 16px 0;font-size:12px;line-height:1.6;color:#6e707a;">
                            Modern fit. Urban vibe. Built for the next generation.
                        </p>
                        <p style="margin:0;font-size:11px;color:#555660;line-height:1.6;">
                            <a href="{{ $unsubscribeUrl }}" target="_blank" rel="noopener" style="color:#8e909a;text-decoration:underline;">
                                Manage preferences
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

{{-- Tracking pixel --}}
<img src="{{ $openPixelUrl }}" width="1" height="1" alt="" border="0" style="display:block;border:0;outline:none;text-decoration:none;width:1px;height:1px;">
</body>
</html>
