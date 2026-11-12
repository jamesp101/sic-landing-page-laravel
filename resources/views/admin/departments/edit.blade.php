<x-admin.editor-page
    :title="'Edit '.$department['name']"
    description="Update the department description, logo and hero content."
    :section="['title' => 'Departments', 'href' => '/admin/departments']"
    crumb="Edit"
    island="admin/departments/DepartmentForm"
    :props="['department' => $department]"
/>
