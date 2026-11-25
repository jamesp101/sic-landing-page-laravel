<script lang="ts">
    let { kind, message }: { kind: 'error' | 'success'; message: string } = $props();
</script>

<!-- An error takes focus after a failed submit (`action-form` finds it by `data-form-message`), so it is focusable. -->
{#if message && kind === 'error'}
    <p
        role="alert"
        tabindex="-1"
        data-form-message
        class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
    >
        {message}
    </p>
{:else if message}
    <p
        role="status"
        class="rounded-md border border-border-strong bg-status-article-bg px-3 py-2 text-sm text-status-article-fg"
    >
        {message}
    </p>
{/if}
