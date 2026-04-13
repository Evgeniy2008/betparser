'use strict';
const fs = require('fs');

const bundle = fs.readFileSync('data/pinnacle_vendors_adc6697c5292d4ffd999_js.js', 'utf8');
const patterns = ['matchup', 'market', 'event', 'fixtures', 'line', '/0.1', '/sports', 'live', 'websocket'];

for (const token of patterns) {
  const escaped = token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp('"([^"\\n]*' + escaped + '[^"\\n]*)"', 'gi');
  const values = new Set();
  let m;
  while ((m = re.exec(bundle)) && values.size < 120) {
    values.add(m[1]);
  }
  console.log('\n##', token, 'count', values.size);
  console.log([...values].slice(0, 40).join('\n'));
}
