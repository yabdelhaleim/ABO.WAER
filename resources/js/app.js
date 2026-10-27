//
// ABO Storefront — front-end entry point
//

import './auth.js';
import './nav.js';
import './intro.js';
import './home.js';
import './shop.js';
import './product.js';
import './size-guide.js';
import './cart.js';
import './checkout.js';
import './orders.js';
// Content pages are rendered server-side from the `pages` table
// (Store\PageController → resources/views/content.blade.php). The
// former front-end-only renderer (content.js + content.mock.js) used
// to overwrite that HTML with static mock data after load; it has been
// removed so the database is the single source of truth.

// ─── Instant page transitions: prefetch internal links on hover/touch ───────
document.addEventListener('mouseover', (e) => {
    const a = e.target.closest('a');
    if (!a || !a.href || a.origin !== window.location.origin) return;
    if (a.target === '_blank' || a.hasAttribute('download') || a.href.includes('#')) return;
    if (a.dataset.prefetched) return;
    a.dataset.prefetched = 'true';

    const link = document.createElement('link');
    link.rel = 'prefetch';
    link.href = a.href;
    document.head.appendChild(link);
}, { passive: true });

document.addEventListener('touchstart', (e) => {
    const a = e.target.closest('a');
    if (!a || !a.href || a.origin !== window.location.origin) return;
    if (a.target === '_blank' || a.hasAttribute('download') || a.href.includes('#')) return;
    if (a.dataset.prefetched) return;
    a.dataset.prefetched = 'true';

    const link = document.createElement('link');
    link.rel = 'prefetch';
    link.href = a.href;
    document.head.appendChild(link);
}, { passive: true });
