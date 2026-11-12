import path from 'node:path';

// E2E_PORT lets several runs coexist: each gets its own server, database and uploads dir.
export const PORT = Number(process.env.E2E_PORT ?? 8399);
/** UPLOAD_DIR of the e2e server (STORAGE_DRIVER=local), relative to the project root. */
export const UPLOADS = `storage/e2e-uploads-${PORT}`;
/** The e2e server's own SQLite database, never the dev database. */
export const DATABASE = path.resolve(`storage/e2e-${PORT}.sqlite`);

/** Environment of every PHP process of the run (migrate, seed, server). */
export const SERVER_ENV: Record<string, string> = {
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_URL: `http://localhost:${PORT}`,
    // Always the production build, also while `devenv up` runs a Vite dev server (`public/hot`).
    VITE_HOT_FILE: path.resolve(`storage/e2e-no-hot-${PORT}`),
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: DATABASE,
    // Readers do not block the writer: the server runs several PHP workers on one SQLite file.
    DB_JOURNAL_MODE: 'wal',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log',
    STORAGE_DRIVER: 'local',
    UPLOAD_DIR: UPLOADS,
    // The built-in PHP server handles one request per worker; islands fetch in parallel.
    PHP_CLI_SERVER_WORKERS: '6',
};
