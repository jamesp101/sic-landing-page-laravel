<x-admin.editor-page
    title="New hero section"
    description="Design a new hero panel for the Main site or a department page with the visual editor."
    :section="['title' => 'Hero Sections', 'href' => '/admin/hero-sections']"
    crumb="New"
    island="admin/hero-sections/HeroSectionForm"
    :props="['departmentField' => $departmentField]"
/>
