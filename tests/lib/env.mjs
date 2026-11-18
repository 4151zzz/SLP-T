import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export const testsRoot = path.resolve(__dirname, '..');
export const projectRoot = path.resolve(testsRoot, '..');
export const fixturesDir = path.join(testsRoot, 'fixtures');

export const SEED_USERS = Object.freeze({
  admin: Object.freeze({
    username: 'admin',
    staffId: 'ADM001',
    email: 'admin@example.org',
    role: 'admin',
    userType: 'internal'
  }),
  staff01: Object.freeze({
    username: 'staff01',
    staffId: 'STF001',
    email: 'staff01@example.org',
    role: 'staff',
    userType: 'internal'
  }),
  head01: Object.freeze({
    username: 'head01',
    staffId: 'HED001',
    email: 'head01@example.org',
    role: 'head',
    userType: 'internal'
  }),
  vice01: Object.freeze({
    username: 'vice01',
    staffId: 'VDR001',
    email: 'vice01@example.org',
    role: 'vice_director',
    userType: 'internal'
  }),
  director01: Object.freeze({
    username: 'director01',
    staffId: 'DIR001',
    email: 'director01@example.org',
    role: 'director',
    userType: 'internal'
  }),
  user01: Object.freeze({
    username: 'user01',
    staffId: 'EMP001',
    email: 'user01@example.org',
    role: 'user',
    userType: 'internal'
  }),
  user02: Object.freeze({
    username: 'user02',
    staffId: 'EMP002',
    email: 'user02@example.org',
    role: 'user',
    userType: 'internal'
  }),
  ext01: Object.freeze({
    username: 'ext01',
    staffId: null,
    email: 'ext01@example.org',
    role: 'external_user',
    userType: 'external'
  })
});

export const CONFIG = Object.freeze({
  projectRoot,
  testsRoot,
  fixturesDir,
  chromePath: process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  dbHost: process.env.DB_HOST || '127.0.0.1',
  dbPort: process.env.DB_PORT || '3306',
  dbName: process.env.DB_NAME || 'slp_admin_db',
  dbUser: process.env.DB_USER || 'root',
  dbPass: process.env.DB_PASS ?? '',
  resetScriptPath: path.join(projectRoot, 'sql', 'reset_and_seed.php'),
  notificationLogPath: path.join(projectRoot, 'storage', 'logs', 'notifications.log'),
  seedPassword: process.env.SEED_PASSWORD || 'Password123!',
  seedUsers: SEED_USERS
});

export default CONFIG;
