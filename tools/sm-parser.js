'use strict'

const fs = require('fs')
const path = require('path')

const inDir = './' + process.argv[2]
const outDir = './' + process.argv[3]

const FILES = ['sm.json', 'police.json', 'stop-agresji.json']

Object.prototype.clone = function() {
	return JSON.parse(JSON.stringify(this))
}

function getShortName(address0) {
	const replacements = [
		['Referat Oskarżycieli Publicznych', 'ROP SM'],
		['Komenda Wojewódzka Policji', 'KWP'],
		['Komenda Stołeczna Policji', 'KSP'],
		['Komenda Powiatowa Policji', 'KPP'],
		['Komenda Miejska Policji', 'KMP'],
		['Komenda Powiatowa', 'KPP'],
		['Komenda Miejska', 'KMP'],
		['Komisariat Policji', 'KP'],
		['Posterunek Policji', 'PP'],
		['Oddział Terenowy', 'OT'],
		['Straż Miejska', 'SM'],
		['Straż Gminna', 'SG'],
	]
	for (const [from, to] of replacements) {
		if (address0.includes(from)) return address0.replace(from, to)
	}
	return address0
}

function processFile(fileName) {
	const inputPath = path.join(inDir, fileName)
	const outputPath = path.join(outDir, fileName)

	const rawdata = fs.readFileSync(inputPath)
	const sm = JSON.parse(rawdata)

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
			sm[e].short = getShortName(sm[e].address[0])
			if (e.toLowerCase() !== e) {
				sm[e.toLowerCase()] = sm[e].clone()
				delete sm[e]
			}
		})

	const smArray = Object.entries(sm).filter(([e, _v]) => e != '_nieznane')

	const isSm = fileName === 'sm.json'
	const isPolice = fileName === 'police.json'

	const zip = smArray.filter(([_e, v]) => v.address[2].search(/\d\d-\d\d\d /) != 0 && v.city != 'Warszawa')
	if(zip.length) console.error(`${fileName} zip code problem:`, zip)

	const email = smArray.filter(([_e, v]) => (v.email == undefined) || v.email?.email?.search(/@/) <= 0)
	if(email.length) console.error(`${fileName} email problem`, email)

	const api = smArray.filter(([_e, v]) => ! ['MailGun', 'Poznan', 'Mail', 'MailGunAlter'].includes(v.api))
	if(api.length) console.error(`${fileName} api problem`, api)

	const straz = isSm
		? smArray.filter(([_e, v]) => !/Straż\w* (Miejsk|Gminn)\w*/i.test(v.address.join(' ')))
		: []
	if(straz.length) console.error(`${fileName} straz phrase problem:`, straz)

	const smPolicja = isSm
		? smArray.filter(([_e, v]) => v.address.join(' ').toLowerCase().includes('policja')
			|| (v.email && v.email.toLowerCase().includes('policja')))
		: []
	if(smPolicja.length) console.error(`${fileName} sm policja problem:`, smPolicja)

	const policjaEmail = isPolice
		? smArray.filter(([_e, v]) => !v.email || !v.email.toLowerCase().includes('policja'))
		: []
	if(policjaEmail.length) console.error(`${fileName} policja email problem:`, policjaEmail)

	fs.writeFileSync(outputPath, JSON.stringify(sm))
}

function lowerKeys(filePath) {
	const data = JSON.parse(fs.readFileSync(filePath))
	return new Set(Object.keys(data).filter(k => k !== '_nieznane').map(k => k.toLowerCase()))
}

FILES.forEach(file => processFile(file))

const filesPaths = FILES.map(f => path.join(outDir, f)).filter(p => fs.existsSync(p))
const allKeys = filesPaths.map(p => ({ file: path.basename(p), keys: lowerKeys(p) }))

for (let i = 0; i < allKeys.length; i++) {
	for (let j = i + 1; j < allKeys.length; j++) {
		const duplicates = [...allKeys[i].keys].filter(k => allKeys[j].keys.has(k))
		if (duplicates.length) {
			console.error(`duplicate keys between ${allKeys[i].file} and ${allKeys[j].file}:`, duplicates)
		}
	}
}
