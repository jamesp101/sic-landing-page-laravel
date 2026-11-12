<x-layouts.public :seo="$seo" :layout="$layout">
    <x-site.page-banner
        eyebrow="Downloads"
        title="Documents & Files"
        lead="Browse official folders and download forms, academic guides, curriculum checklists and department schedules."
    />

    <section class="min-h-[50vh] py-12 md:py-16" aria-label="Files">
        <div class="page-frame space-y-10">
            <x-site.filter-bar
                action="/downloads"
                :search="$filters['search'] ?? ''"
                searchLabel="Search files"
                searchPlaceholder="Document name, tag or folder…"
                :departmentId="$filters['departmentId'] ?? null"
                :departments="$layout['departments']"
                :hidden="($filters['directory'] ?? null) !== null ? ['directory' => $filters['directory']] : []"
                :active="\App\Site\PostFilters::hasDownloadFilters($filters)"
                clearHref="/downloads"
            />
            <x-site.download-browser
                :downloads="$listing['downloads']"
                :groups="$listing['groups']"
                :folders="$listing['folders']"
                :selected="$listing['selected']"
                :allHref="$listing['allHref']"
                :totalCount="$listing['totalCount']"
            />
        </div>
    </section>
</x-layouts.public>
