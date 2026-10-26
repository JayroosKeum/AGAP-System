const fs = require('fs');
const html = fs.readFileSync('frontend/pages/complaints/complaint-details.php', 'utf8');
const idMatches = html.match(/id=["']([^"']+)["']/g) || [];
const ids = [...new Set(idMatches.map(m => m.replace(/id=["']|["']/g, '')))];
console.log('Paanyaya & Summons & Hearing IDs:');
console.log(ids.filter(id => 
  id.toLowerCase().includes('paanyaya') || 
  id.toLowerCase().includes('summon') || 
  id.toLowerCase().includes('modal') ||
  id.toLowerCase().includes('return') ||
  id.toLowerCase().includes('schedule')
));
