// Signed-in storage states written by auth.setup.ts, one directory per E2E_PORT run so
// concurrent runs (each with its own DB) never share session cookies.
import { PORT } from './env';

const DIR = `e2e/.auth/${PORT}`;

export const ADMIN = `${DIR}/admin.json`;
export const CIT = `${DIR}/cit.json`;
