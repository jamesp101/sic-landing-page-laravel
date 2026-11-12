<script lang="ts">
    import { actions } from '@/lib/actions';
    import { navigate } from '@/lib/navigation';
    import { untrack } from 'svelte';
    import BlockEditor from '../editor/BlockEditor.svelte';
    import UnsavedChangesGuard from '../shared/UnsavedChangesGuard.svelte';
    import { unsavedChanges } from '../shared/unsaved-changes.svelte';
    import { actionForm } from '../shared/action-form.svelte';
    import Field from '../shared/Field.svelte';
    import FormActions from '../shared/FormActions.svelte';
    import ImageField from '../shared/ImageField.svelte';
    import { append, inputClass } from '../shared/form-utils';
    import { cardClass } from '../ui';

    export interface DepartmentFormValues {
        id: number;
        name: string;
        description: string | null;
        heroContent: string | null;
        logoUrl: string | null;
    }

    let { department = null }: { department?: DepartmentFormValues | null } = $props();

    const initial = untrack(() => department);
    let name = $state(initial?.name ?? '');
    let description = $state(initial?.description ?? '');
    let heroContent = $state(initial?.heroContent ?? '');
    let logo = $state<File | null>(null);

    const unsaved = unsavedChanges(() => [name, description, heroContent, logo !== null]);

    const form = actionForm({
        fieldByCode: { CONFLICT: 'name' },
        run() {
            const data = new FormData();
            if (initial) append(data, 'id', initial.id);
            append(data, 'name', name);
            append(data, 'description', description);
            append(data, 'heroContent', heroContent);
            append(data, 'logo', logo);
            return initial ? actions.departments.update(data) : actions.departments.create(data);
        },
        onSuccess: () => {
            unsaved.markSaved();
            return navigate('/admin/departments');
        },
    });
</script>

<form method="post" onsubmit={form.submit} data-unsaved-own class="space-y-6">
    <div class="{cardClass} space-y-6 p-6">
        <Field id="name" label="Department name" required error={form.errors.name}>
            {#snippet children(props)}
                <input
                    {...props}
                    type="text"
                    required
                    maxlength="255"
                    bind:value={name}
                    class={inputClass}
                    placeholder="e.g. College Department"
                />
            {/snippet}
        </Field>

        <Field id="description" label="Short description" error={form.errors.description}>
            {#snippet children(props)}
                <textarea
                    {...props}
                    rows="3"
                    bind:value={description}
                    class={inputClass}
                    placeholder="Brief overview of the department."
                ></textarea>
            {/snippet}
        </Field>

        <ImageField
            bind:file={logo}
            id="logo"
            target="logo"
            label="Department logo"
            existingUrl={initial?.logoUrl ?? null}
            aspectRatio={1}
            cropTitle="Crop Department Logo (1:1)"
            hint="PNG, JPG, WebP or GIF (max 5 MB)"
            error={form.errors.logo}
        />
    </div>

    <BlockEditor
        bind:value={heroContent}
        id="hero-content"
        label="Hero content"
        error={form.errors.heroContent}
        context="section"
    />

    <FormActions
        cancelHref="/admin/departments"
        processing={form.processing}
        submitLabel={initial ? 'Save changes' : 'Save department'}
        message={form.message}
    />
</form>

<UnsavedChangesGuard dirty={unsaved.dirty} />
