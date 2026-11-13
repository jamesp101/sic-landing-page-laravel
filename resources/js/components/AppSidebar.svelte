<script lang="ts">
    import FileText from '@lucide/svelte/icons/file-text';
    import Download from '@lucide/svelte/icons/download';
    import Home from '@lucide/svelte/icons/house';
    import Image from '@lucide/svelte/icons/image';
    import LayoutGrid from '@lucide/svelte/icons/layout-grid';
    import Link2 from '@lucide/svelte/icons/link-2';
    import School from '@lucide/svelte/icons/school';
    import SettingsIcon from '@lucide/svelte/icons/settings';
    import Sparkles from '@lucide/svelte/icons/sparkles';
    import Network from '@lucide/svelte/icons/network';
    import UsersIcon from '@lucide/svelte/icons/users';
    import { onMount } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import ScopeSwitcher from '@/components/admin/ScopeSwitcher.svelte';
    import type { AdminUser, NavItem, ScopeOption, ScopeSummary } from '@/components/admin/types';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
        useSidebar,
    } from '@/components/ui/sidebar';

    let {
        user,
        navItems,
        scope,
        scopeDepartments,
        scopeLocked,
    }: {
        user: AdminUser;
        /** The sidebar links (`App\Admin\AdminNav::items`). */
        navItems: NavItem[];
        scope: ScopeSummary;
        scopeDepartments: ScopeOption[];
        scopeLocked: boolean;
    } = $props();

    const { isMobile, setOpenMobile } = useSidebar();
    let openSignal = $state(0);

    // Page-level "change" links ask the switcher to open (on mobile the sheet opens first).
    onMount(() => {
        const request = () => {
            if ($isMobile) setOpenMobile(true);
            openSignal += 1;
        };
        window.addEventListener('admin:open-scope', request);
        return () => window.removeEventListener('admin:open-scope', request);
    });

    /** The Lucide icons `navItems` name (`App\Admin\AdminNav`). */
    const NAV_ICONS = {
        'layout-grid': LayoutGrid,
        'file-text': FileText,
        download: Download,
        sparkles: Sparkles,
        school: School,
        network: Network,
        image: Image,
        'link-2': Link2,
        users: UsersIcon,
        settings: SettingsIcon,
        house: Home,
    };
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <a {...props} href="/dashboard" class={props.class}>
                            <AppLogo />
                        </a>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
        <ScopeSwitcher {scope} departments={scopeDepartments} locked={scopeLocked} {openSignal} />
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={navItems} icons={NAV_ICONS} />
    </SidebarContent>

    <SidebarFooter>
        <NavUser {user} />
    </SidebarFooter>
</Sidebar>
