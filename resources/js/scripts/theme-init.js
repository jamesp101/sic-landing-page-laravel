// Runs inline in <head> (BaseLayout) before first paint so a dark theme never flashes light.
// Blade inlines this exact file (with the CSP nonce); keep it self-contained.
(() => {
    const apply = (root) => {
        const stored = localStorage.getItem('appearance');
        const value = stored === 'light' || stored === 'dark' ? stored : 'system';
        const dark = value === 'dark' || (value === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        root.classList.toggle('dark', dark);
        root.style.colorScheme = dark ? 'dark' : 'light';
    };
    apply(document.documentElement);
})();
