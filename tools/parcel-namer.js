const plugin = require('@parcel/plugin')
const Namer = plugin.Namer
const path = require('path')

module.exports = new Namer({
  name({bundle}) {
    const me = bundle.getMainEntry()
    if (!me) return null
    
    const filename = path.parse(me.filePath)
    let ext = filename.ext
    if (bundle.type == 'css')
        ext = '.css'
    
    let subdir = ''
    const imageSubdir = me.filePath.match(/.*\/src\/img\/(.*)\/.*\..*/)
    if (imageSubdir)
      subdir = imageSubdir[1] + '/'
    return `${subdir}${filename.name}${ext}`
  }
})
