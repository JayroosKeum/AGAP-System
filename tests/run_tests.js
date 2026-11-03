const { execSync } = require('child_process');
const phpPath = 'C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe';

const suites = [
  { name: 'Paanyaya E2E Test Suite', file: 'backend/tests/test_paanyaya_e2e.php' },
  { name: 'KP Workflow Integration (KP Forms 10-20)', file: 'backend/tests/test_kp_workflow_e2e.php' },
  { name: 'Scheduling & Working Hours Rules', file: 'backend/tests/test_scheduling_fixes.php' },
  { name: 'CFA & Voluntary Arbitration Workflows', file: 'backend/tests/test_cfa_and_arbitration_workflows_e2e.php' },
  { name: 'Lupon Clerk Dashboard & Metrics', file: 'backend/tests/test_clerk_dashboard.php' },
  { name: 'Lupon Member Dashboard, Duty & Dossier', file: 'backend/tests/test_lupon_dashboard.php' }
];

console.log('====================================================================');
console.log('         AGAP SYSTEM AUTOMATED TEST RUNNER (npm test)              ');
console.log('====================================================================\n');

let passed = 0;
let failed = 0;

for (const suite of suites) {
  process.stdout.write(`▶ Running ${suite.name}... `);
  try {
    execSync(`"${phpPath}" "${suite.file}"`, { stdio: 'pipe' });
    console.log('✅ PASSED');
    passed++;
  } catch (err) {
    console.log('❌ FAILED');
    console.error(err.stdout ? err.stdout.toString() : err.message);
    failed++;
  }
}

console.log('\n====================================================================');
console.log(`SUMMARY: ${passed} PASSED, ${failed} FAILED (Total: ${suites.length})`);
console.log('====================================================================');

if (failed > 0) {
  process.exit(1);
} else {
  process.exit(0);
}

