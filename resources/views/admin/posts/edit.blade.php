<x-admin.editor-page
    title="Edit post"
    :description="'Make changes to “'.$post->title.'”.'"
    :section="['title' => 'Posts', 'href' => '/admin/posts']"
    crumb="Edit"
    island="admin/posts/PostForm"
    :props="['departmentField' => $departmentField, 'post' => $values]"
/>
