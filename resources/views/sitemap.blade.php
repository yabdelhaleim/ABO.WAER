{{--
    ABO — /sitemap.xml

    Rendered by App\Http\Controllers\SitemapController as application/xml.

    Two constraints shaped the declaration below:

    1. It is echoed from a PHP block rather than written as a literal
       `<?xml` line, because Blade compiles this file into PHP and a literal
       `<?xml` would open a PHP tag on a host with short_open_tag=On.
       Echoing also puts the declaration at byte 0 of the response —
       leading whitespace before it makes strict parsers reject the
       whole document.

    2. DO NOT name the Blade PHP directive inside this comment. Blade runs
       storePhpBlocks() BEFORE compileComments(), so a directive name
       written here opens a phantom block that swallows everything down to
       the next real directive tag — taking the urlset element with it.
       The comment below therefore says "PHP block" in prose.

    Every interpolated value goes through Blade's escaped echo, which
    converts `&` to `&amp;` — valid XML and exactly what the sitemap schema
    wants for the category query string. Never switch these to unescaped
    echo.

    Priorities (Google weighs the relative order loosely, but keeping the
    homepage on top documents intent):
      1.0  home        0.9  shop
      0.8  category    0.7  product
      0.5  content page

    Only active rows reach this template — see the controller's class
    docblock for the scopes that keep archived / unpublished URLs out.
--}}
@php
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
@endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

    {{-- Homepage — the canonical entry point. --}}
    <url>
        <loc>{{ route('home') }}</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    {{-- Catalog landing page. --}}
    <url>
        <loc>{{ route('shop') }}</loc>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>

    {{-- Category landing pages — the same filter URLs the sidebar links to. --}}
    @foreach ($categories as $category)
        <url>
            <loc>{{ route('shop', ['category' => $category->slug]) }}</loc>
            <lastmod>{{ $category->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
        </url>
    @endforeach

    {{-- Active product pages. `lastmod` is the W3C datetime format Google asks for. --}}
    @foreach ($products as $product)
        <url>
            <loc>{{ route('shop.show', ['slug' => $product->slug]) }}</loc>
            <lastmod>{{ $product->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.7</priority>
        </url>
    @endforeach

    {{-- Content pages (about / contact / shipping / returns / exchange).
         Each slug is also its route name — see SitemapController::CONTENT_ROUTES. --}}
    @foreach ($pages as $page)
        <url>
            <loc>{{ route($page->slug) }}</loc>
            <lastmod>{{ $page->updated_at->toAtomString() }}</lastmod>
            <changefreq>monthly</changefreq>
            <priority>0.5</priority>
        </url>
    @endforeach

</urlset>