const libphonenumber = require('libphonenumber-js');
const phone = '+3809948118899';
const parsed = libphonenumber.parsePhoneNumber(phone);
console.log('Phone:', phone);
console.log('Country:', parsed.country);
console.log('Valid:', parsed.isValid());
console.log('Possible:', parsed.isPossible());
