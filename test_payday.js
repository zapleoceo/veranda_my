const fs = require('fs');
const js = fs.readFileSync('payday2/assets/js/payday2.js', 'utf8');
// let's evaluate it in a JSDOM environment or similar to see if it throws.
