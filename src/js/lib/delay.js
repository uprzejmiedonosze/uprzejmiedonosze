
/**
 * @param {{ apply: (arg0: any, arg1: IArguments) => void; }} callback
 * @param {number} ms
 */
function delay(callback, ms) {
    var timer = null
    return function() {
      var context = this, args = arguments
      clearTimeout(timer)
      timer = setTimeout(function () {
        callback.apply(context, args)
      }, ms || 0)
    }
  }
  
  export default delay