import {Namer} from '@parcel/plugin';
import path from 'path';

export default new Namer({
  name({bundle}) {
    let dir = null
    if (['png', 'jpg', 'jpeg', 'gif', 'pdf', 'webm', 'ogv', 'mp4'].includes(bundle.type))
      dir = 'img'
    if (['css'].includes(bundle.type))
      dir = 'css'
    if (['js'].includes(bundle.type))
      dir = 'js'
    if (['html'].includes(bundle.type))
      dir = 'templates'
      
    if (dir) {
      const me = bundle.getMainEntry()
      if (!me) return null;
      const filename = path.parse(me.filePath)
      let hash = ''
      if (!bundle.needsStableName) {
        hash = "." + bundle.hashReference;
      }
      let ext = filename.ext
      if (bundle.type == 'css')
        ext = '.css'
      return `${dir}/${filename.name}${hash}${ext}`;
    }

    // Allow the next namer to handle this bundle.
    return null;
  }
});
