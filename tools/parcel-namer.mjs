import {Namer} from '@parcel/plugin';
import path from 'path';

export default new Namer({
  name({bundle}) {
    const me = bundle.getMainEntry()
    if (!me) return null;
    
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
});
