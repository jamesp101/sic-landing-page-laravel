<script lang="ts">
    import {
        SidebarGroup,
        SidebarGroupLabel,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import type { Component } from 'svelte';
    import type { NavItem } from '@/components/admin/types';

    let {
        items = [],
        icons,
    }: {
        items: NavItem[];
        /** Lucide components by the names `items` use. */
        icons: Record<string, Component<{ class?: string }>>;
    } = $props();
</script>

<SidebarGroup class="px-2 py-0">
    <SidebarGroupLabel>Platform</SidebarGroupLabel>
    <SidebarMenu>
        {#each items as item (item.href)}
            {@const Icon = icons[item.icon]}
            <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={item.active} tooltip={item.title}>
                    {#snippet children(props)}
                        <a
                            {...props}
                            href={item.href}
                            aria-current={item.active ? 'page' : undefined}
                            class={props.class}
                        >
                            {#if Icon}
                                <Icon class="size-4 shrink-0" />
                            {/if}
                            <span>{item.title}</span>
                        </a>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        {/each}
    </SidebarMenu>
</SidebarGroup>
