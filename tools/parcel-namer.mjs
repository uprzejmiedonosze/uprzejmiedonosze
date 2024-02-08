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
    return `${filename.name}${ext}`
  }
});
