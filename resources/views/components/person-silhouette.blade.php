{{--
    Silhouetted person used as every person avatar that has no photo (admin user menu, Org chart).
    Fills its container: put it in a box with `overflow-hidden` and a rounded shape. Twin: `PersonSilhouette.svelte`.
--}}
@props(['class' => ''])
<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" class="{{ trim('size-full '.$class) }}"><circle cx="12" cy="9" r="4.5" /><path d="M2.5 24c0-5.2 4.3-8.8 9.5-8.8s9.5 3.6 9.5 8.8z" /></svg>
