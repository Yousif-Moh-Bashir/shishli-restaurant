<title>{{ $page['title'] }}</title>
<meta name="description" content="{{ $page['description'] }}">
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:title" content="{{ $page['title'] }}">
<meta property="og:description" content="{{ $page['description'] }}">
<meta name="twitter:title" content="{{ $page['title'] }}">
<meta name="twitter:description" content="{{ $page['description'] }}">
@if($page['image'])<meta property="og:image" content="{{ $page['image'] }}">@endif
@if($page['schema'])
<script id="{{ $page['schema']['@type'] === 'Restaurant' ? 'restaurant-jsonld' : 'catalog-jsonld' }}" type="application/ld+json">@json($page['schema'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)</script>
@endif
