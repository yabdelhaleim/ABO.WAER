// ABO — Content pages mock data.
//
// Scope (per spec §0, §5, §6):
//   - This is the SINGLE source of truth for the 5 content pages
//     (Shipping, Returns, Exchange, About, Contact) and the
//     page-template settings.
//   - No backend, no network, no storage. The renderer in
//     content.js reaches this file only through two wrappers:
//     `getPage(slug)` and `getSettings()`. Swapping them for real
//     API calls later is a one-line change in content.js.
//   - All text fields support the inline subset defined in spec §4
//     (`**bold**`, `*italic*`, `[label](url)`, `{{vars}}`); the
//     renderer parses it — never inject raw HTML.
//   - Variables (`{{free_shipping}}`, `{{return_days}}`,
//     `{{exchange_days}}`) are resolved at render time from
//     SETTINGS so the dev panel can update them live.
//
// Phone numbers, prices, history, and the address below are
// PLACEHOLDERS (spec §6 footer) — they live only in this mock file.

export const SETTINGS = {
    freeShippingThreshold: 1500,
    returnDays: 14,
    exchangeDays: 14,
    currency: 'EGP',
};

// ─── Page data ─────────────────────────────────────────────────────

export const PAGES = {
    // ─── Shipping policy ──────────────────────────────────────────
    shipping: {
        slug: 'shipping',
        group: 'policy',
        title: 'Shipping policy',
        summary:
            'Fast delivery across Egypt, tracked from the moment your order leaves our hands.',
        updatedAt: '2026-10-12',
        status: 'published',
        seo: {
            title: 'Shipping policy — ABO',
            description:
                'Fast delivery across Egypt, tracked from the moment your order leaves our hands. Free shipping over 1,500 EGP and cash on delivery available.',
        },
        blocks: [
            {
                type: 'stats',
                items: [
                    { value: '1–2d', label: 'CAIRO & GIZA DELIVERY' },
                    { value: '{{free_shipping}}', label: 'FREE SHIPPING OVER' },
                    { value: 'COD', label: 'CASH ON DELIVERY AVAILABLE' },
                ],
            },
            {
                type: 'text',
                heading: 'Processing time',
                paragraphs: [
                    'Orders placed before **2 PM** ship the same day. Orders placed after 2 PM ship the next business day.',
                    'You will receive an **SMS + email** with a tracking link as soon as your order leaves our warehouse.',
                ],
            },
            {
                type: 'table',
                heading: 'Delivery times & fees',
                columns: ['Area', 'Time', 'Fee'],
                rows: [
                    ['Cairo & Giza', '1–2 business days', '60 EGP'],
                    ['Alexandria & Delta', '2–3 business days', '75 EGP'],
                    ['Upper Egypt & Canal', '3–5 business days', '90 EGP'],
                    ['Remote areas', '5–7 business days', '110 EGP'],
                ],
                footnote:
                    'Orders over {{free_shipping}} ship free to all areas.',
            },
            {
                type: 'note',
                title: 'Good to know',
                body:
                    'The courier will attempt delivery **twice**. Keep your phone on — the driver will call before arriving.',
            },
            {
                type: 'faq',
                heading: 'Shipping FAQ',
                items: [
                    {
                        question: 'Can I change the delivery address after ordering?',
                        answer:
                            'Yes, only if the order has not shipped yet. Reach out via [Contact us](/contact) with your order number and the new address.',
                    },
                    {
                        question: 'Do you ship outside Egypt?',
                        answer:
                            'Not yet — we are working on it. Join the newsletter on the home page to be notified when international shipping goes live.',
                    },
                    {
                        question: 'How do I track my order?',
                        answer:
                            'Every order has a tracking link sent by SMS and email. You can also find it under [My Orders](/orders).',
                    },
                ],
            },
        ],
    },

    // ─── Returns policy ───────────────────────────────────────────
    returns: {
        slug: 'returns',
        group: 'policy',
        title: 'Returns policy',
        summary: 'Not feeling it? Send it back, no drama.',
        updatedAt: '2026-10-12',
        status: 'published',
        seo: {
            title: 'Returns policy — ABO',
            description:
                'Send it back within 14 days. Free courier pickup, refund in 3–7 business days.',
        },
        blocks: [
            {
                type: 'stats',
                items: [
                    { value: '{{return_days}}d', label: 'RETURN WINDOW' },
                    { value: 'Free', label: 'COURIER PICKUP' },
                    { value: '3–7d', label: 'REFUND TIME' },
                ],
            },
            {
                type: 'steps',
                heading: 'How to return',
                items: [
                    {
                        title: 'Request it',
                        description:
                            'Open the order in [My Orders](/orders) and tap **Return item**.',
                    },
                    {
                        title: 'Pack it',
                        description:
                            'Tags on, unworn, original packaging. We will email a prepaid label.',
                    },
                    {
                        title: 'We collect',
                        description:
                            'A courier picks the package up from your door — at no cost to you.',
                    },
                ],
            },
            {
                type: 'text',
                heading: 'What can be returned',
                paragraphs: [
                    'Items can be returned within **{{return_days}} days** of delivery, as long as they are unworn, unwashed, and the original tags are still attached. The item must be in its original packaging.',
                    'We inspect every return within **48 hours** of receiving it. If everything checks out, the refund is triggered the same day.',
                ],
            },
            {
                type: 'note',
                title: 'Not returnable',
                body:
                    'Final Sale items, worn or washed pieces, and anything that has been altered or monogrammed.',
            },
            {
                type: 'table',
                heading: 'How you get refunded',
                columns: ['Paid with', 'Refund to', 'Time'],
                rows: [
                    ['Card', 'Original card', '5–7 business days'],
                    [
                        'Cash on delivery',
                        'Store credit or wallet transfer',
                        '3–5 business days',
                    ],
                ],
            },
            {
                type: 'faq',
                heading: 'Returns FAQ',
                items: [
                    {
                        question: 'Who pays for return shipping?',
                        answer:
                            'We do. Every return inside the {{return_days}}-day window includes a free courier pickup.',
                    },
                    {
                        question: 'I missed the return window. What now?',
                        answer:
                            'Reach out via [Contact us](/contact) — we review every case individually and may extend the window for unopened items.',
                    },
                ],
            },
        ],
    },

    // ─── Exchange policy ─────────────────────────────────────────
    exchange: {
        slug: 'exchange',
        group: 'policy',
        title: 'Exchange policy',
        summary: 'Wrong size? Swap it for the right one.',
        updatedAt: '2026-10-12',
        status: 'published',
        seo: {
            title: 'Exchange policy — ABO',
            description:
                'Swap your size or color within 14 days. One free exchange per order.',
        },
        blocks: [
            {
                type: 'stats',
                items: [
                    { value: '{{exchange_days}}d', label: 'EXCHANGE WINDOW' },
                    { value: '1', label: 'FREE EXCHANGE PER ORDER' },
                    { value: 'Any', label: 'SIZE OR COLOR' },
                ],
            },
            {
                type: 'steps',
                heading: 'How to exchange',
                items: [
                    {
                        title: 'Pick the new size',
                        description:
                            'Open the size guide to confirm before you request the swap.',
                    },
                    {
                        title: 'Request it',
                        description:
                            'Message [Contact us](/contact) with your order number and the size or color you want.',
                    },
                    {
                        title: 'We swap it',
                        description:
                            'We arrange the pickup and ship the replacement as soon as the original is on its way back.',
                    },
                ],
            },
            {
                type: 'text',
                heading: 'Conditions',
                paragraphs: [
                    'Exchanges are accepted within **{{exchange_days}} days** of delivery. The item must be unworn, with original tags attached, and the new size or color must be in stock.',
                    'Each order includes **one free exchange**. Any further size swaps are treated as a return + new order.',
                ],
            },
            {
                type: 'note',
                title: 'Price difference',
                body:
                    'If the new item costs more, you pay the difference. If it costs less, we refund the difference as **store credit**.',
            },
            {
                type: 'faq',
                heading: 'Exchange FAQ',
                items: [
                    {
                        question: 'The size I want is out of stock.',
                        answer:
                            'We can put you on a waitlist for the next restock, or refund the difference as store credit so you can pick something else.',
                    },
                    {
                        question: 'Is the exchange shipping free?',
                        answer:
                            'Yes — the first exchange per order is on us, both ways.',
                    },
                ],
            },
        ],
    },

    // ─── About us ────────────────────────────────────────────────
    about: {
        slug: 'about',
        group: 'info',
        eyebrow: 'ABOUT ABO',
        title: 'About us',
        summary:
            'Urban / Streetwear / Lifestyle. Built in Cairo for the next generation.',
        updatedAt: '2026-10-12',
        status: 'published',
        seo: {
            title: 'About us — ABO',
            description:
                'Urban streetwear built in Cairo for the next generation. Premium fabric, modern fit, and a better drop every season.',
        },
        blocks: [
            {
                type: 'hero',
                headline: 'Not made to **blend in.**',
                body: 'ABO started in 2024 with a simple idea: premium menswear that fits right, holds up, and looks like nobody else on the street. Everything we drop is designed in Cairo and built to outlast the season it was made for.',
                cta: { label: 'Shop the drop', href: '/shop' },
            },
            {
                type: 'stats',
                items: [
                    { value: '2024', label: 'FOUNDED' },
                    { value: '120+', label: 'DESIGNS' },
                    { value: '{{return_days}}d', label: 'EASY RETURNS' },
                ],
            },
            {
                type: 'values',
                heading: 'What we stand for',
                items: [
                    {
                        number: '01',
                        title: 'Premium fabric',
                        description:
                            'Heavyweight cotton, brushed fleece, real leather. Nothing thin, nothing shiny, nothing that falls apart after three washes.',
                    },
                    {
                        number: '02',
                        title: 'Modern fit',
                        description:
                            'Cut for movement, never skin-tight, never sloppy. Designed on real bodies in Cairo, not on a runway mannequin.',
                    },
                    {
                        number: '03',
                        title: 'Better than yesterday',
                        description:
                            'Every drop is a refinement of the last. We ship fewer pieces, more often, and each one earns its place in the lineup.',
                    },
                ],
            },
            {
                type: 'timeline',
                heading: 'Our story',
                items: [
                    {
                        label: '2024',
                        title: 'The first drop',
                        description:
                            'Eight pieces, one weekend, sold out in 36 hours. A quiet start that turned into something louder.',
                    },
                    {
                        label: '2025',
                        title: 'Growing the crew',
                        description:
                            'Two more drops, a real warehouse, and a small studio in Maadi. The team grew from three to twelve.',
                    },
                    {
                        label: '2026',
                        title: 'Drop 02',
                        description:
                            'A new season, a new fit block, and a wider range. The next chapter — and the one we are most proud of so far.',
                    },
                ],
            },
            {
                type: 'note',
                title: 'Brand promise',
                body:
                    'If something does not fit right, [send it back](/returns) or [swap it](/exchange) — no friction, no fine print.',
            },
        ],
    },

    // ─── Contact us ──────────────────────────────────────────────
    contact: {
        slug: 'contact',
        group: 'info',
        eyebrow: 'GET IN TOUCH',
        title: 'Contact us',
        summary:
            'Questions about an order, a size or a drop? We reply within 24 hours.',
        updatedAt: '2026-10-12',
        status: 'published',
        seo: {
            title: 'Contact us — ABO',
            description:
                'Reach ABO by WhatsApp, phone, or email. Studio in Maadi, Cairo. We reply within 24 hours.',
        },
        blocks: [
            {
                type: 'cards',
                items: [
                    {
                        label: 'WHATSAPP',
                        value: '+20 10 1234 5678',
                        description: 'Fastest way to reach us — usually under an hour.',
                        href: 'https://wa.me/201012345678',
                    },
                    {
                        label: 'PHONE',
                        value: '+20 2 2735 4128',
                        description: 'Sun–Thu, 10 AM–6 PM.',
                        href: 'tel:+20227354128',
                    },
                    {
                        label: 'EMAIL',
                        value: 'hello@abo.example.com',
                        description: 'For order issues, wholesale, and press.',
                        href: 'mailto:hello@abo.example.com',
                    },
                    {
                        label: 'STUDIO',
                        value: 'Maadi, Cairo',
                        description: 'Visits by appointment only.',
                        href: 'https://maps.google.com/?q=Maadi,Cairo',
                    },
                ],
            },
            {
                type: 'cols',
                items: [
                    {
                        type: 'form',
                        heading: 'Send us a message',
                        topics: [
                            'Order status',
                            'Size help',
                            'Returns & exchange',
                            'Wholesale',
                            'Something else',
                        ],
                    },
                    {
                        type: 'hours',
                        heading: 'Working hours',
                        rows: [
                            ['Sun – Thu', '10 AM – 6 PM'],
                            ['Friday', 'Closed'],
                        ],
                        social: [
                            { label: 'Instagram', href: 'https://instagram.com/abo' },
                            { label: 'TikTok', href: 'https://tiktok.com/@abo' },
                            { label: 'Facebook', href: 'https://facebook.com/abo' },
                        ],
                        address: 'ABO Studio, 12 Street 9, Maadi, Cairo, Egypt',
                    },
                ],
            },
            {
                type: 'faq',
                heading: 'Quick answers',
                items: [
                    {
                        question: 'Where is my order?',
                        answer:
                            'Open [My Orders](/orders) to track every order in real time, including the courier link.',
                    },
                    {
                        question: 'How long until you reply?',
                        answer:
                            'WhatsApp usually under an hour. Email within 24 hours, every day of the week.',
                    },
                ],
            },
        ],
    },
};
