{{-- `values`, `fieldErrors`, `formError`, `sent`: the no-JS form round trip (see ContactController). The island
     replaces the server-rendered form in its slot once mounted. --}}
@php
    $seo = [
        'title' => 'Contact & Admissions',
        'description' => 'Contact San Isidro College in Impalambong, Malaybalay City, Bukidnon: phone, email, campus address and an online inquiry form.',
        'canonical' => '/contact',
        'breadcrumbs' => [['label' => 'Home', 'href' => '/'], ['label' => 'Contact']],
    ];
    $fields = [
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'autocomplete' => 'name'],
        ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'autocomplete' => 'email'],
        ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'autocomplete' => 'off'],
    ];
    $input = str_replace('bg-surface', 'bg-background', \App\Site\Classes::FIELD).' aria-[invalid=true]:border-destructive';
    $label = 'mb-1.5 block text-sm font-semibold text-foreground';
@endphp
<x-layouts.public :seo="$seo" :layout="$layout">
    <x-site.page-banner
        eyebrow="Contact"
        title="Contact & Admissions"
        lead="Get in touch with the college about admissions, programs and campus information."
    />

    <section class="py-16 md:py-24">
        <div class="page-frame grid grid-cols-1 gap-12 lg:grid-cols-2">
            <div>
                <x-site.eyebrow>Visit or call</x-site.eyebrow>
                <h2 class="mb-6 mt-3 text-3xl font-bold text-brand-800 dark:text-foreground">Campus information</h2>
                <x-site.contact-info-card />
            </div>

            <div class="self-start rounded-2xl border border-border bg-surface p-8 shadow-brand-md">
                <h2 class="text-3xl font-bold text-brand-800 dark:text-foreground">Send us a message</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Fill out the form and the college will reply to your email address.
                </p>
                <x-island
                    name="site/ContactForm"
                    :props="['action' => '/contact', 'values' => $values, 'fieldErrors' => $fieldErrors, 'formError' => $formError, 'sent' => $sent]"
                >
                    <div role="status" aria-live="{{ $formError ? 'assertive' : 'polite' }}" aria-atomic="true">
                        @if ($sent)
                            <p class="rounded-xl bg-status-article-bg p-4 text-sm font-medium text-status-article-fg">
                                Thank you. Your message has been sent and the college will reply to your email address.
                            </p>
                        @elseif ($formError)
                            <p class="rounded-xl bg-status-announcement-bg p-4 text-sm font-medium text-status-announcement-fg">{{ $formError }}</p>
                        @endif
                    </div>
                    <form method="post" action="/contact" class="mt-6 space-y-5">
                        <p class="text-sm text-muted-foreground">All fields are required.</p>
                        @foreach ($fields as $f)
                            <div>
                                <label for="contact-{{ $f['name'] }}" class="{{ $label }}">{{ $f['label'] }}</label>
                                <input
                                    id="contact-{{ $f['name'] }}"
                                    name="{{ $f['name'] }}"
                                    type="{{ $f['type'] }}"
                                    required
                                    maxlength="255"
                                    autocomplete="{{ $f['autocomplete'] }}"
                                    value="{{ $values[$f['name']] ?? '' }}"
                                    @if (! empty($fieldErrors[$f['name']])) aria-invalid="true" aria-describedby="contact-{{ $f['name'] }}-error" @endif
                                    class="{{ $input }}"
                                />
                                @if (! empty($fieldErrors[$f['name']]))
                                    <p id="contact-{{ $f['name'] }}-error" class="mt-1.5 text-sm text-destructive">{{ $fieldErrors[$f['name']][0] }}</p>
                                @endif
                            </div>
                        @endforeach
                        <div>
                            <label for="contact-message" class="{{ $label }}">Message</label>
                            <textarea
                                id="contact-message"
                                name="message"
                                rows="6"
                                required
                                maxlength="5000"
                                @if (! empty($fieldErrors['message'])) aria-invalid="true" aria-describedby="contact-message-error" @endif
                                class="{{ $input }}"
                            >{{ $values['message'] ?? '' }}</textarea>
                            @if (! empty($fieldErrors['message']))
                                <p id="contact-message-error" class="mt-1.5 text-sm text-destructive">{{ $fieldErrors['message'][0] }}</p>
                            @endif
                        </div>
                        <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                            <label for="contact-website">Leave this field empty</label>
                            <input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off" />
                        </div>
                        <button type="submit" class="{{ \App\Site\Classes::BTN_GOLD }} w-full">Send message</button>
                    </form>
                </x-island>
            </div>
        </div>
    </section>
</x-layouts.public>
