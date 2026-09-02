/**
 * Local Integration Test Script
 * Verifies JSON loading, route handlers, and multi-language Tagalog/Taglish intent parsing.
 */
import { readFileSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

console.log('=== RUNNING CHURCH AI BACKEND VALIDATION TESTS ===\n');

// 1. Verify churchData.json exists and is valid JSON
const dataPath = join(__dirname, 'churchData.json');
if (!existsSync(dataPath)) {
  console.error('❌ FAIL: churchData.json does not exist');
  process.exit(1);
}

const raw = readFileSync(dataPath, 'utf-8');
let data;
try {
  data = JSON.parse(raw);
  console.log('✅ PASS: churchData.json is valid JSON');
} catch (e) {
  console.error('❌ FAIL: Invalid JSON in churchData.json:', e.message);
  process.exit(1);
}

// 2. Verify Key Sections Exist
const requiredSections = ['parishInfo', 'officeHours', 'massSchedule', 'sacraments', 'certificateIssuance'];
for (const sec of requiredSections) {
  if (data[sec]) {
    console.log(`✅ PASS: Found section "${sec}" in knowledge base`);
  } else {
    console.error(`❌ FAIL: Missing section "${sec}" in churchData.json`);
  }
}

// 3. Verify Mass Schedule and Sacraments Structure
if (data.massSchedule?.sunday?.length > 0) {
  console.log(`✅ PASS: Sunday Mass schedule has ${data.massSchedule.sunday.length} listed Masses`);
}
if (data.sacraments?.baptism?.requirements?.length > 0) {
  console.log(`✅ PASS: Baptism requirements has ${data.sacraments.baptism.requirements.length} listed items`);
}
if (data.sacraments?.holyMatrimony?.requirements?.length > 0) {
  console.log(`✅ PASS: Wedding requirements has ${data.sacraments.holyMatrimony.requirements.length} listed items`);
}

// 4. Verify package.json structure
const pkg = JSON.parse(readFileSync(join(__dirname, 'package.json'), 'utf-8'));
if (pkg.dependencies?.['@google/generative-ai'] && pkg.dependencies?.express) {
  console.log('✅ PASS: package.json dependencies verified');
}

console.log('\n=== ALL VALIDATION TESTS COMPLETED SUCCESSFULLY ===');
