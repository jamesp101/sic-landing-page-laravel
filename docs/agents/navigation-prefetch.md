# Navigation, prefetch and the staff hint

## Staff hint

- `sic_staff` is a value-free, non-httpOnly cookie, because the session cookie is httpOnly and scripts cannot see it. The public prefetch script stays off while it is present, so a signed-in person served cached anonymous HTML triggers no prefetch.
- `RequestPolicy` sets or clears it after the response is built, from the final response: `RequestPolicyRules::staffHintAction(['signedIn', 'hinted', 'cacheControl'])` returns `'set'`, `'delete'` or `null`, and acts only on `no-store` responses, so a shared cache never stores a `Set-Cookie`.
- The cookie is written as a raw `Set-Cookie` header and is excluded from cookie encryption (`bootstrap/app.php`, `encryptCookies(except: …)`), because scripts read it as plain `1`.
- `RequestPolicyRules::cookieValue` (URI-decoded when it can be) reads cookies from a `Cookie` header; `hasStaffHint` uses it.
