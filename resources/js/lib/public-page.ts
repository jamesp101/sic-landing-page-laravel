/**
 * Whether a pathname is a public HTML page: not a signed-in/private route, not a static asset, not a feed.
 * Anonymous responses for these are cacheable, so the public hover prefetch (`scripts/public-prefetch.ts`)
 * only targets them. Mirrors the server's route classes (`App\Http\RequestPolicyRules`); `tests/Unit/Platform/fixtures/route-classes.json` pins both.
 */
const PRIVATE = [
    /^\/dashboard(\/|$)/,
    /^\/admin(\/|$)/,
    /^\/settings(\/|$)/,
    /^\/confirm-password$/,
    /^\/verify-email(\/|$)/,
    /^\/api\//,
    /^\/_actions(\/|$)/,
    /^\/login$/,
    /^\/forgot-password$/,
    /^\/reset-password$/,
    /^\/csrf-token$/,
];

/** Static assets: Vite's build output and stored files. */
const isAssetPath = (pathname: string) => pathname.startsWith('/build/') || pathname.startsWith('/storage/');

export const isPublicPage = (pathname: string) =>
    !PRIVATE.some((re) => re.test(pathname)) && !isAssetPath(pathname) && !pathname.endsWith('.xml');
