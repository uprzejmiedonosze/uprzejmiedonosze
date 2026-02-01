'use strict'

const fs = require('fs')

// Usage: node tools/badges-validator.js <input_badges_file> <output_file>
// Example: node tools/badges-validator.js src/api/config/badges.json export/public/api/config/badges.json

const input = process.argv[2]
const output = process.argv[3]
const categoriesFile = 'src/api/config/categories.json'

try {
    const badgesData = fs.readFileSync(input, 'utf8')
    const badges = JSON.parse(badgesData)

    const categoriesData = fs.readFileSync(categoriesFile, 'utf8')
    const categories = JSON.parse(categoriesData)

    const errors = []

    Object.entries(badges).forEach(([badgeKey, badge]) => {
        const badgeCategories = badge.categories
        
        if (Array.isArray(badgeCategories) && badgeCategories.length > 0) {
            const activeCategories = badgeCategories.filter(catId => {
                const cat = categories[catId]
                return cat && cat.title // Checks if title is truthy (not null, not empty string)
            })

            if (activeCategories.length === 0) {
                errors.push(`Badge "${badgeKey}" has categories [${badgeCategories.join(', ')}] but none are active (all have empty title in categories.json).`)
            }
        }
    })

    if (errors.length > 0) {
        console.error('Validation failed for badges:')
        errors.forEach(err => console.error(` - ${err}`))
        process.exit(1)
    }

    // Write output minified (stripping whitespace)
    fs.writeFileSync(output, JSON.stringify(badges))

} catch (err) {
    console.error('An error occurred in badges-validator:', err)
    process.exit(1)
}
