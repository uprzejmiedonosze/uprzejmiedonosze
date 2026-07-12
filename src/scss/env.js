const host = process.env.HOST || 'uprzejmiedonosze.net'

// Marka (zieleń) jest stała we wszystkich środowiskach — patrz lib/tokens.scss.
// env.js steruje już tylko dyskretnym wskaźnikiem środowiska: kolorem kropki
// w headerze na dev/staging (ukrytym na produkcji).
let tint = '#0088bb' // dev + staging

if (host === 'uprzejmiedonosze.net')
    tint = 'transparent' // prod: wskaźnik ukryty

console.log(`:root {
  --env-tint: ${tint};
}\n`)
