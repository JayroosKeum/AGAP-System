const { spawnSync } = require('node:child_process');
const path = require('node:path');

const suites = {
  paanyaya: 'test_paanyaya_e2e.php',
  scheduling: 'test_scheduling_fixes.php',
  cfa: 'test_cfa_and_arbitration_workflows_e2e.php',
  lupon: 'test_lupon_dashboard.php',
  conciliation: 'test_conciliation_transition_and_timeline_e2e.php'
};

const requested = process.argv.slice(2);
const selected = requested.length ? requested : Object.keys(suites);
const unknown = selected.filter((name) => !Object.hasOwn(suites, name));
if (unknown.length) {
  console.error('Unknown test suite:', unknown.join(', '));
  process.exit(1);
}

if (!process.env.DB_NAME) {
  console.error('Set DB_NAME and the connection settings for an isolated local AGAP test database.');
  process.exit(1);
}
const dbHost = process.env.DB_HOST || '127.0.0.1';
if (!['127.0.0.1', 'localhost'].includes(dbHost)) {
  console.error('AGAP integration tests require a local MySQL test database.');
  process.exit(1);
}

const php = process.env.PHP_BINARY || 'php';
const options = {
  cwd: path.resolve(__dirname, '..'),
  env: { ...process.env, DB_HOST: dbHost },
  encoding: 'utf8'
};
const preflight = spawnSync(php, ['-r', [
  'if (!extension_loaded("mbstring") || !extension_loaded("pdo_mysql")) {',
  '    fwrite(STDERR, "Tests require the PHP mbstring and pdo_mysql extensions." . PHP_EOL); exit(1);',
  '}',
  'require "backend/config/database.php";',
  '$db = (new Database())->connect();',
  '$db->query("SELECT invitation_number FROM paanyaya_preparations LIMIT 0");',
  'echo "AGAP test database ready";'
].join('\n')], options);
if (preflight.error || preflight.status !== 0 || preflight.stdout.trim() !== 'AGAP test database ready') {
  console.error('Unable to start AGAP tests:', preflight.error?.message || preflight.stderr || preflight.stdout);
  process.exit(1);
}

let failed = 0;
for (const name of selected) {
  const file = path.join('backend', 'tests', suites[name]);
  console.log('\nRunning ' + name + ' (' + file + ')');
  const result = spawnSync(php, [file], { ...options, stdio: 'inherit' });
  if (result.status !== 0) {
    failed++;
    if (result.error) console.error(result.error.message);
    if (result.signal) console.error('Terminated by', result.signal);
  }
}
console.log('\n' + (selected.length - failed) + ' passed, ' + failed + ' failed');
process.exitCode = failed ? 1 : 0;
