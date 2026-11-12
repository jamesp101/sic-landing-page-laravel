import { execFileSync } from 'node:child_process';

/**
 * The demo content the specs look up by name. Single source: `Database\Seeders\DemoData` (PHP), printed as JSON by
 * `php artisan sic:demo-data` and loaded once when this module is imported.
 */
export type DemoDepartmentKey = 'cit' | 'cba' | 'coe';

export interface DemoDepartment {
    name: string;
    slug: string;
    description: string;
    heroContent: string;
}

export interface DemoUser {
    name: string;
    email: string;
    role: 'superadmin' | 'department_user';
    department: DemoDepartmentKey | null;
}
export type DemoUserKey = 'superAdmin' | 'cit' | 'cba';

export interface DemoPost {
    title: string;
    content: string;
    type: 'announcement' | 'event' | 'article';
    startDate: Date | null;
    endDate: Date | null;
    department: DemoDepartmentKey | null;
    author: DemoUserKey;
    isFeatured: boolean;
}

export interface DemoHeroSection {
    title: string;
    content: string;
    order: number;
    isActive: boolean;
    department: DemoDepartmentKey | null;
}

export interface DemoMember {
    name: string;
    role: string;
    department: DemoDepartmentKey;
    /** Key of another member of the same department, listed before this one. */
    parent: string | null;
    order: number;
}

interface Raw {
    DEMO_DEPARTMENTS: Record<DemoDepartmentKey, DemoDepartment>;
    DEMO_USERS: Record<DemoUserKey, DemoUser>;
    DEMO_POSTS: Record<
        'enrolment' | 'foundationWeek' | 'aiLab' | 'quantumSeminar' | 'entrepreneurshipAward',
        Omit<DemoPost, 'startDate' | 'endDate'> & { startDate: string | null; endDate: string | null }
    >;
    DEMO_HERO_SECTIONS: Record<'welcome' | 'campusLife' | 'cit' | 'cba', DemoHeroSection>;
    DEMO_MEMBERS: Record<
        'citDean' | 'citCsHead' | 'citIsHead' | 'citInstructor' | 'citWebInstructor' | 'cbaDean' | 'cbaChair',
        DemoMember
    >;
    WELCOME_HERO: string;
    AI_LAB_HEADING: string;
    AI_LAB_QUOTE: string;
    DEMO_DOWNLOAD_NAV_CHILDREN: string[];
    SEED_SUPERADMIN_PASSWORD: string;
    SEED_DEPARTMENT_PASSWORD: string;
}

const raw = JSON.parse(
    execFileSync('php', ['artisan', 'sic:demo-data'], {
        encoding: 'utf8',
        env: process.env,
        stdio: ['ignore', 'pipe', 'inherit'],
    }),
) as Raw;

export const DEMO_DEPARTMENTS = raw.DEMO_DEPARTMENTS;
export const DEMO_USERS = raw.DEMO_USERS;
export const WELCOME_HERO = raw.WELCOME_HERO;
export const AI_LAB_HEADING = raw.AI_LAB_HEADING;
export const AI_LAB_QUOTE = raw.AI_LAB_QUOTE;
export const DEMO_DOWNLOAD_NAV_CHILDREN = raw.DEMO_DOWNLOAD_NAV_CHILDREN;
export const DEMO_HERO_SECTIONS = raw.DEMO_HERO_SECTIONS;
export const DEMO_MEMBERS = raw.DEMO_MEMBERS;

const date = (value: string | null) => (value === null ? null : new Date(value));
export const DEMO_POSTS = Object.fromEntries(
    Object.entries(raw.DEMO_POSTS).map(([key, post]) => [
        key,
        { ...post, startDate: date(post.startDate), endDate: date(post.endDate) },
    ]),
) as Record<keyof Raw['DEMO_POSTS'], DemoPost>;

/** The Password of a seeded User, as the PHP seeder resolves it. */
export function seedPassword(envName: 'SEED_SUPERADMIN_PASSWORD' | 'SEED_DEPARTMENT_PASSWORD'): string {
    return raw[envName];
}
