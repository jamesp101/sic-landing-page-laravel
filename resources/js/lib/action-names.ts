/**
 * Every server action as `module → actions`, the names `actions.<module>.<action>` accepts at compile time.
 * Held equal to `ActionRegistry::all()` through `tests/Unit/Platform/fixtures/actions.json` (PHPUnit checks the registry
 * against it, `actions.test.ts` checks this list against it).
 */
export const ACTION_NAMES = {
    account: ['changePassword', 'confirmPassword', 'deleteAccount', 'updateProfile'],
    auth: ['forgotPassword', 'login', 'logout', 'resetPassword', 'sendVerificationEmail'],
    carousels: ['create', 'delete', 'duplicate', 'placement', 'reorder', 'update'],
    contact: ['send'],
    departments: ['create', 'delete', 'update'],
    directories: ['create', 'delete'],
    downloads: ['create', 'delete'],
    heroSections: ['create', 'delete', 'update'],
    members: ['create', 'delete', 'reorder', 'update'],
    navlinks: ['create', 'delete', 'reorder', 'update', 'useDefaultMenu'],
    posts: ['create', 'delete', 'update'],
    settings: ['updateLanding'],
    users: ['create', 'delete', 'update'],
} as const;

export type ActionModules = typeof ACTION_NAMES;
