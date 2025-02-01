'use strict'

const fs = require('fs')

const input = './' + process.argv[2]
const output = './' + process.argv[3]

const rawdata = fs.readFileSync(input)
const sm = JSON.parse(rawdata)

Object.prototype.clone = function() {
	return JSON.parse(JSON.stringify(this))
}

Object.entries(sm)
	.filter(([_e, v]) => v.parent)
	.forEach(([e, v]) => {
		const orig = sm[e].clone()
		sm[e] = sm[v.parent].clone()
		if (orig.hint) sm[e].hint = orig.hint
		else {
			var city = e
			if (city.startsWith('gmina') || city.startsWith('powiat')) {
				city = city.replace('gmina', 'Gminę')
				city = city.replace('powiat', 'Powiat')
			} else city = `${e}` 
			sm[e].hint = `${city} obsługuje ${sm[e].address[0]}`
				+ ( (sm[e].hint) ? `: ${sm[e].hint}` : '')
		}
		if (orig.city) sm[e].city = orig.city
	})

Object.entries(sm)
	.forEach(([e, _v]) => {
		if (e === '_nieznane') return
		if (!sm[e].hint) sm[e].hint = 'Masz doświadczenia we współpracy z tą jednostką? <a href="mailto:szymon@uprzejmiedonosze.net" target="_blank">Podziel się</a>.'
		if (!sm[e].api) sm[e].api = 'MailGun'
		if (!sm[e].city) sm[e].city = e
		if (e.toLowerCase() !== e) {
			sm[e.toLowerCase()] = sm[e].clone()
			delete sm[e]
		}
	})

const smArray = Object.entries(sm).filter(([e, _v]) => e != '_nieznane')

const zip = smArray.filter(([_e, v]) => v.address[2].search(/\d\d-\d\d\d /) != 0 && v.city != 'Warszawa')
if(zip.length) console.error('zip code problem:', zip)

const email = smArray.filter(([_e, v]) => v.email.search(/@/) <= 0)
if(email.length) console.error('email problem', email)

const api = smArray.filter(([_e, v]) => ! ['MailGun', 'Poznan', 'Mail'].includes(v.api))
if(api.length) console.error('api problem', api)

if ((zip.length + email.length + api.length) != 0) process.exit(1)

fs.writeFileSync(output, JSON.stringify(sm))
