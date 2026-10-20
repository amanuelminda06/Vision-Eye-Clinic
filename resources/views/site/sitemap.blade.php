<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($paths as $path)
    <url>
        <loc>{{ $base }}{{ $path }}</loc>
        <lastmod>{{ $now }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>{{ $path === '/' ? '1.0' : '0.7' }}</priority>
        <xhtml:link rel="alternate" hreflang="en" href="{{ $base }}{{ $path }}?lang=en" />
        <xhtml:link rel="alternate" hreflang="am" href="{{ $base }}{{ $path }}?lang=am" />
    </url>
@endforeach
</urlset>
