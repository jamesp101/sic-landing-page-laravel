<x-layouts.app
    title="Upload file"
    :breadcrumbs="[
        ['title' => 'Dashboard', 'href' => '/dashboard'],
        ['title' => 'Downloads', 'href' => '/admin/downloads'],
        ['title' => 'Upload'],
    ]"
>
    <div class="{{ \App\Admin\Ui::FORM_PAGE }}">
        <x-admin.page-header
            title="Upload file"
            description="Publish a form, manual or resource to the public downloads page."
            backHref="/admin/downloads"
        />
        <x-island
            name="admin/downloads/DownloadUploadForm"
            :props="[
                'defaultDirectory' => $defaultDirectory,
                'directories' => $directories,
                'departmentField' => $departmentField,
                'maxMb' => $maxMb,
                'uploadMode' => $uploadMode,
            ]"
        />
    </div>
</x-layouts.app>
