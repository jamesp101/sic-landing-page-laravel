<x-admin.editor-page
    title="Edit hero section"
    :description="'Make changes to “'.($section->title === null || $section->title === '' ? 'Hero section' : $section->title).'”.'"
    :section="['title' => 'Hero Sections', 'href' => '/admin/hero-sections']"
    crumb="Edit"
    island="admin/hero-sections/HeroSectionForm"
    :props="['departmentField' => $departmentField, 'section' => $values]"
/>
