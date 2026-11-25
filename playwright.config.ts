import { defineConfig, devices } from '@playwright/test';
import { DATABASE, PORT, SERVER_ENV, UPLOADS } from './e2e/env';

// Every run starts from an empty, migrated, seeded database and upload dir, so tests never write into dev data.
// Needs a production build (`pnpm run build`): the server must not find `public/hot`.
const prepare = [
    `rm -rf '${UPLOADS}' '${DATABASE}' '${DATABASE}-wal' '${DATABASE}-shm'`,
    `touch '${DATABASE}'`,
    'php artisan config:clear',
    'php artisan migrate --force',
    'php artisan db:seed --force',
].join(' && ');
// PHP's built-in server with Laravel's router script (it takes the working directory as the public path);
// `artisan serve` would not pass this environment on.
const serve = `cd public && php -S 127.0.0.1:${PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;

export default defineConfig({
    testDir: 'e2e',
    fullyParallel: true,
    // All workers share one PHP server; more than 3 starves it and turns island waits into flaky timeouts.
    workers: process.env.CI ? 2 : 3,
    timeout: 60_000,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: { baseURL: `http://localhost:${PORT}`, trace: 'retain-on-failure' },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        // Server-vs-island layout specs compare two loads of the same page; they only read, and run before any spec that
        // writes data (Navigation links, posts, …) can change the page between those loads.
        {
            name: 'layout-parity',
            testMatch: /(site-header|admin-shell)\.spec\.ts/,
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
        },
        {
            name: 'chromium',
            testIgnore: /(site-header|admin-shell)\.spec\.ts/,
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup', 'layout-parity'],
        },
    ],
    webServer: {
        command: `${prepare} && ${serve}`,
        port: PORT,
        reuseExistingServer: false,
        timeout: 120_000,
        env: SERVER_ENV,
    },
});
