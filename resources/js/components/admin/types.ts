export interface BreadcrumbItem {
    title: string;
    href?: string;
}

/** Signed-in user as shown in the admin shell (subset of App.Locals['user']). */
export interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: string;
}

/** One admin sidebar link, as `App\Admin\AdminNav::items` lists it: `icon` is a Lucide name, `active` the current page. */
export interface NavItem {
    title: string;
    href: string;
    icon: string;
    active: boolean;
}

export interface ScopeOption {
    id: number;
    name: string;
    logoUrl: string | null;
}

/** Resolved admin scope as shown in the sidebar switcher. */
export interface ScopeSummary {
    kind: 'all' | 'main' | 'department';
    id: number | null;
    label: string;
}
