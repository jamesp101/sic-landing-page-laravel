<div data-island="{{ $name }}" data-props="{{ json_encode((object) $props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}" {{ $attributes }}>{{ $slot }}</div>
