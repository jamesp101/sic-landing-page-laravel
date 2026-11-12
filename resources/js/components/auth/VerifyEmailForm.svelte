<script lang="ts">
    import { Button } from '@/components/ui/button';
    import { actions } from '@/lib/actions';
    import { navigate } from '@/lib/navigation';
    import Notice from './Notice.svelte';

    let processing = $state(false);
    let status = $state('');
    let error = $state('');

    async function resend(event: SubmitEvent) {
        event.preventDefault();
        processing = true;
        status = '';
        error = '';
        try {
            const { error: failure } = await actions.auth.sendVerificationEmail({});
            if (failure)
                error =
                    failure.code === 'TOO_MANY_REQUESTS'
                        ? 'Too many requests. Please wait a minute.'
                        : 'Could not send the email. Please try again.';
            else status = 'A new verification link has been sent to your email address.';
        } catch {
            error = 'Could not reach the server. Please try again.';
        } finally {
            processing = false;
        }
    }

    async function logout() {
        await actions.auth.logout({});
        await navigate('/login');
    }
</script>

<div class="grid gap-6 text-center">
    <Notice kind="success" message={status} />
    <Notice kind="error" message={error} />
    <form method="post" onsubmit={resend}>
        <Button type="submit" variant="secondary" class="h-11" disabled={processing}>
            {processing ? 'Sending…' : 'Resend verification email'}
        </Button>
    </form>
    <button
        type="button"
        onclick={logout}
        class="mx-auto inline-flex min-h-11 items-center text-sm text-link underline decoration-border-strong underline-offset-4 hover:text-brand-700"
    >
        Log out
    </button>
</div>
