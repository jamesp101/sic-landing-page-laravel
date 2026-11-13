<x-admin.editor-page
    title="New post"
    description="Publish a new article, event advisory or announcement."
    :section="['title' => 'Posts', 'href' => '/admin/posts']"
    crumb="New"
    island="admin/posts/PostForm"
    :props="['departmentField' => $departmentField]"
/>
