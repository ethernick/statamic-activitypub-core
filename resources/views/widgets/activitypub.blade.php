<div class="card card-lg p-0 content">
    <header>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>

    </header>
    <div class="grid lg:grid-cols-2 p-4">
        <a href="{{ $link }}" class="super-btn">
            @cp_svg('icons/light/earth')
            <div class="flex-1">
                <h3>{{ __($button_title) }}</h3>
                <p>{{ __($button_description) }}</p>
            </div>
        </a>
    </div>
</div>