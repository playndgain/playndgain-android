{{-- Placement cell: icon or letter, name, public app ID, host.
     Same identity layout as the publisher user column. --}}
@php
    use App\Models\App;
    use Illuminate\Support\Facades\Storage;

    $state = $getState();
    $app = $state instanceof App
        ? $state
        : ($record instanceof App ? $record : ($record->app ?? null));
    $app = $app instanceof App ? $app : null;
    $name = $app?->name ?: (is_string($state) ? $state : null);

    $icon = null;
    if ($app !== null && filled($app->icon_path)) {
        $icon = Storage::disk('public')->url($app->icon_path);
    }

    $letter = filled($name) ? mb_strtoupper(mb_substr(trim($name), 0, 1)) : '—';

    $host = null;
    if ($app !== null && filled($app->url)) {
        $host = parse_url((string) $app->url, PHP_URL_HOST) ?: null;
    }
@endphp

@if ($app === null && blank($name))
    <p class="fi-ta-placeholder ad-user">{{ $getPlaceholder() ?: '—' }}</p>
@else
    <div class="ad-user">
        <span class="ad-user__mark" aria-hidden="true">
            @if (filled($icon))
                <img src="{{ $icon }}" alt="" loading="lazy">
            @else
                {{ $letter }}
            @endif
        </span>
        <div class="ad-user__body">
            <span class="ad-user__name">{{ $name }}</span>
            @if ($app !== null || filled($host))
                <span class="ad-user__meta ad-user__line">
                    @if ($app !== null)
                        <span class="ad-user__email"
                              role="button"
                              tabindex="0"
                              title="Copy app ID"
                              x-data="{
                                  copied: false,
                                  copy() {
                                      navigator.clipboard.writeText(@js((string) $app->app_id));
                                      this.copied = true;
                                      setTimeout(() => this.copied = false, 1400);
                                  },
                              }"
                              x-on:click.stop="copy()"
                              x-on:keydown.enter.stop="copy()"
                              x-bind:data-copied="copied">
                            <span x-show="! copied">#{{ $app->app_id }}</span>
                            <span x-cloak x-show="copied">Copied</span>
                        </span>
                    @endif
                    @if ($app !== null && filled($host))
                        <span class="ad-user__sep" aria-hidden="true">·</span>
                    @endif
                    @if (filled($host))
                        <span class="ad-user__host">{{ $host }}</span>
                    @endif
                </span>
            @endif
        </div>
    </div>
@endif
