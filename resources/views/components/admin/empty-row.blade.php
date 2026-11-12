{{--
    Empty list row. Props: colspan, noun (plural, lower case), filtered (a search/filter is active: nothing matches),
    createHref + createLabel (empty variant only; omit when the actor cannot create one).
--}}
@props(['colspan', 'noun', 'filtered', 'createHref' => null, 'createLabel' => null])
<tr>
    <td colspan="{{ $colspan }}" class="px-6 py-12 text-center text-muted-foreground">
        @if ($filtered)
            <div class="flex flex-col items-center gap-1">
                <p class="font-semibold text-foreground">No {{ $noun }} match your filters.</p>
                <p class="text-sm">Try a different search, or reset the filters to see every row.</p>
            </div>
        @else
            <div class="flex flex-col items-center gap-4">
                <div class="space-y-1">
                    <p class="font-semibold text-foreground">No {{ $noun }} yet.</p>
                    @if ($createHref)
                        <p class="text-sm">Create the first one to get started.</p>
                    @endif
                </div>
                @if ($createHref && $createLabel)
                    <a href="{{ $createHref }}" class="{{ \App\Admin\Ui::PRIMARY_BUTTON }}">{{ $createLabel }}</a>
                @endif
            </div>
        @endif
    </td>
</tr>
