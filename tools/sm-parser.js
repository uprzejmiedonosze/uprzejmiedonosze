'use strict'

const fs = require('fs')

const rawdata = fs.readFileSync('./' + process.argv[2]);
const sm = JSON.parse(rawdata);

Object.entries(sm)
	.filter(([_e, v]) => v.parent)
	.forEach(([e, v]) => sm[e] = sm[v.parent])

const smArray = Object.entries(sm).filter(([e, _v]) => e != '_nieznane')

const zip = smArray.filter(([_e, v]) => v.address[2].search(/\d\d-\d\d\d /) != 0 && v.city != 'Warszawa')
if(zip.length) console.log('zip code', zip)

const email = smArray.filter(([_e, v]) => v.email.search(/@/) <= 0)
if(email.length) console.log('email', email)

const api = smArray.filter(([_e, v]) => ! ['Mail', 'Poznan'].includes(v.api))
if(api.length) console.log('api', api)

if (zip.length + email.length + api.length != 0) process.exit(1)

console.log(JSON.stringify(sm))
