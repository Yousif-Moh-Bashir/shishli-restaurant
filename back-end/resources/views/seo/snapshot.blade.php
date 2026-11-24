<div id="prerender-root" class="site-layout">
    <main class="container section-space">
        <h1>{{ $page['heading'] }}</h1>
        @if ($page['image'])
            <img src="{{ $page['image'] }}" alt="{{ $page['heading'] }}">
        @endif
        @if ($page['intro'])
            <p>{{ $page['intro'] }}</p>
        @endif
        @foreach ($page['sections'] as $section)
            <section>
                <h2>{{ $section['title'] }}</h2>
                <p>{{ $section['text'] }}</p>
            </section>
        @endforeach
        @if ($page['items'])
            <ul>
                @foreach ($page['items'] as $item)
                    <li>
                        @if ($item['href'])
                            <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
                        @else
                            <span>{{ $item['label'] }}</span>
                        @endif
                        @if (!empty($item['image']))
                            <img src="{{ $item['image'] }}" alt="{{ $item['label'] }}" loading="lazy">
                        @endif
                        @if (!empty($item['text']))
                            <p>{{ $item['text'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <nav aria-label="روابط الموقع">
            <ul>
                @foreach ($page['links'] as $link)
                    <li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        </nav>
    </main>
</div>
<script type="application/json" id="prerender-data">@json($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)</script>
