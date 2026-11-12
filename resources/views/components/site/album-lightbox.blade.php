{{-- Marks a page whose rendered content has an album. The `site` entry (resources/js/site.ts) imports the lightbox
     script when `[data-album]` exists; render this next to content only when `Content::hasAlbum($html)`. --}}
<span hidden data-album-lightbox></span>
