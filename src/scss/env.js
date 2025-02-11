const host = process.env.HOST || 'uprzejmiedonosze.net'

let color = '#0088bb' // dev+staging

if (host === 'uprzejmiedonosze.net')
    color = '#009C7F'
if (host == 'shadow.uprzejmiedonosze.net')
    color = '#ff4081'

console.log(`:root {
  --color: ${color};
};\n`)
