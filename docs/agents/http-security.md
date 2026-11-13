# Request policy, CSP and caching

`App\Http\RequestPolicyRules` (pure) owns the route classes, the login redirect and the response headers; `App\Http\Middleware\RequestPolicy` applies them. It is the last middleware of the `web` group, so it runs after the session and the signed-in User are known.

## Request policy

- A signed-out call to any action except the `Access::Public` ones gets 401 in the action error envelope before the action runs. The list is the `#[Action(Access::Public)]` declarations (`ActionRegistry::isPublic`, handed to `RequestPolicyRules::rejectsAnonymousAction`); a new public action needs only its declaration.
- Any other signed-out request that can carry a body (not GET/HEAD) gets 413 unless `Content-Length` is present and at most 64 KB.
- Anonymous visitors of protected routes (`/dashboard`, `/admin`, `/settings`, `/confirm-password`, `/verify-email…`) are redirected to `/login?redirect=…`.
- Private routes (protected ones, `/api/`, `/_actions`, `/login`, `/forgot-password`, `/reset-password`, `/csrf-token`) are `noindex, nofollow` and `no-store`.
- Errors thrown by middleware outside the policy (CSRF 419, throttling) are answered without the policy headers.

## CSP

Every response carries `BASE_CSP_DIRECTIVES` (frame-ancestors, object-src, base-uri, form-action). HTML adds `script-src 'self' 'nonce-…'` plus the hash of Svelte's `this.__e=event` img handler under `'unsafe-hashes'`, and `style-src 'self' 'unsafe-inline'`. The middleware calls `Vite::useCspNonce()` before the view renders: `@vite` tags and the inline theme script carry `nonce="{{ Vite::cspNonce() }}"`. Any other inline script or `on*=` attribute is blocked. In local development the Vite dev server origin is added to `script-src` and `style-src` (there is no `connect-src`, so its websocket is not restricted). `/storage/` sets its own policy.

## Caching and cookies

- Anonymous GET responses for public pages get `PUBLIC_CACHE_CONTROL`; private routes, signed-in users and non-GET requests get `no-store`. Assets (`/build/`, `/storage/`) get no policy `Cache-Control`.
- A cacheable anonymous response carries no `Set-Cookie` (the response's header bag is swapped for `CookielessHeaderBag`, so the session and XSRF cookies added by outer middleware are dropped) and does not store a session when the visitor arrived without a session cookie. Public HTML therefore has no `<meta name="csrf-token">`; a script that has to POST fetches `GET /csrf-token` (`{ token }`, no-store) first.
- Anonymous public HTML gets a weak ETag over the body without its nonce; a matching `If-None-Match` is answered 304 without a CSP header (the client keeps the policy and nonce of the copy it holds). Compression is the web server's job.
