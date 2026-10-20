// ABO — "My Orders" mock data.
//
// Single source of truth for the front-end-only "My Orders" page.
// All data the page renders comes from here, accessed only via
// `getOrders()` (defined in orders.js). Swapping that function for
// a real API call is the only change required to wire a real
// backend — no view, component or styling change is needed.
//
// Schema (each order):
//   id           string  e.g. "ABO-10482"
//   date         ISO string ("2026-10-12")
//   dateLabel    string  pre-formatted, e.g. "Oct 12, 2026"
//   step         0..4    0=Placed, 1=Packed, 2=Shipped, 3=Out for delivery, 4=Delivered
//   cancelled    bool    true when the order was cancelled
//   etaText      string  lime short-line shown under the stepper
//   items[]      { name, variant, qty, price }   price in EGP (integer)
//   shipping     number  EGP
//   payment      string  human-readable (e.g. "COD", "Visa ••42")
//   address      string  short location
//   returnOpen   bool    whether the 14-day return window is still open
//   timeline[]   { title, time, status: "done"|"pending"|"cancelled" }
//
// Timestamps and timestamps[] are rendered as-is — they are display
// strings, not machine time. They are independent of the user's
// real clock because the page is a static preview.

export const ORDERS = [
    {
        id: 'ABO-10501',
        date: '2026-10-14',
        dateLabel: 'Oct 14, 2026',
        step: 1, // Packed
        cancelled: false,
        etaText: 'Estimated delivery Oct 17–18',
        items: [
            { name: 'Night Shell Jacket', variant: 'L · Black', qty: 1, price: 2100 },
        ],
        shipping: 0,
        payment: 'Cash on delivery',
        address: 'Nasr City',
        returnOpen: false,
        timeline: [
            { title: 'Order placed',     time: 'Oct 14, 2026 · 09:14', status: 'done' },
            { title: 'Payment confirmed', time: 'Oct 14, 2026 · 09:15', status: 'done' },
            { title: 'Packed in warehouse', time: 'Oct 14, 2026 · 18:02', status: 'done' },
            { title: 'Shipped',           time: '—', status: 'pending' },
            { title: 'Out for delivery',  time: '—', status: 'pending' },
            { title: 'Delivered',         time: '—', status: 'pending' },
        ],
    },
    {
        id: 'ABO-10482',
        date: '2026-10-12',
        dateLabel: 'Oct 12, 2026',
        step: 3, // Out for delivery
        cancelled: false,
        etaText: 'Arriving today · courier is nearby',
        items: [
            { name: 'Shadow Oversized Hoodie', variant: 'L · Black', qty: 1, price: 1450 },
            { name: 'Core Heavy Tee',          variant: 'M · White', qty: 2, price: 650 },
        ],
        shipping: 0,
        payment: 'Paid online · Visa ••42',
        address: 'Maadi',
        returnOpen: true,
        timeline: [
            { title: 'Order placed',     time: 'Oct 12, 2026 · 11:42', status: 'done' },
            { title: 'Payment confirmed', time: 'Oct 12, 2026 · 11:43', status: 'done' },
            { title: 'Packed in warehouse', time: 'Oct 12, 2026 · 19:30', status: 'done' },
            { title: 'Shipped',           time: 'Oct 13, 2026 · 08:11', status: 'done' },
            { title: 'Out for delivery',  time: 'Oct 15, 2026 · 07:55', status: 'done' },
            { title: 'Delivered',         time: '—', status: 'pending' },
        ],
    },
    {
        id: 'ABO-10391',
        date: '2026-10-05',
        dateLabel: 'Oct 5, 2026',
        step: 4, // Delivered
        cancelled: false,
        etaText: 'Delivered Oct 8 · return window open until Oct 22',
        items: [
            { name: 'Cargo Utility Pants', variant: '32 · Olive', qty: 1, price: 1200 },
        ],
        shipping: 60,
        payment: 'Cash on delivery',
        address: 'Nasr City',
        returnOpen: true,
        timeline: [
            { title: 'Order placed',        time: 'Oct 5, 2026 · 14:08', status: 'done' },
            { title: 'Packed in warehouse', time: 'Oct 6, 2026 · 09:20', status: 'done' },
            { title: 'Shipped',             time: 'Oct 6, 2026 · 16:00', status: 'done' },
            { title: 'Out for delivery',    time: 'Oct 8, 2026 · 07:42', status: 'done' },
            { title: 'Delivered',           time: 'Oct 8, 2026 · 11:14', status: 'done' },
        ],
    },
    {
        id: 'ABO-10277',
        date: '2026-09-21',
        dateLabel: 'Sep 21, 2026',
        step: 0, // not used for cancelled
        cancelled: true,
        etaText: 'Cancelled by you · refund issued',
        items: [
            { name: 'Night Shell Jacket', variant: 'M · Black', qty: 1, price: 2100 },
        ],
        shipping: 0,
        payment: 'Visa ••42',
        address: 'Maadi',
        returnOpen: false,
        timeline: [
            { title: 'Order placed',            time: 'Sep 21, 2026 · 22:17', status: 'cancelled' },
            { title: 'Cancelled by you',        time: 'Sep 22, 2026 · 08:45', status: 'cancelled' },
            { title: 'Refund issued to Visa ••42', time: 'Sep 23, 2026 · 14:02', status: 'cancelled' },
        ],
    },
    {
        id: 'ABO-10190',
        date: '2026-08-30',
        dateLabel: 'Aug 30, 2026',
        step: 4, // Delivered
        cancelled: false,
        etaText: 'Delivered Sep 2 · return window closed',
        items: [
            { name: 'Core Heavy Tee', variant: 'L · Black', qty: 2, price: 650 },
        ],
        shipping: 60,
        payment: 'Cash on delivery',
        address: 'Nasr City',
        returnOpen: false, // 14 days after Sep 2 → expired on Sep 16
        timeline: [
            { title: 'Order placed',        time: 'Aug 30, 2026 · 10:11', status: 'done' },
            { title: 'Packed in warehouse', time: 'Aug 31, 2026 · 08:00', status: 'done' },
            { title: 'Shipped',             time: 'Aug 31, 2026 · 16:24', status: 'done' },
            { title: 'Out for delivery',    time: 'Sep 2, 2026 · 08:10',  status: 'done' },
            { title: 'Delivered',           time: 'Sep 2, 2026 · 12:48',  status: 'done' },
        ],
    },
];

export default ORDERS;