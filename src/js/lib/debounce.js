function debounce(callback, wait = 300, context = this) {
  let timeout = null;
  let callbackArgs = null;

  const later = () => callback.apply(context, callbackArgs);

  return function() {
    callbackArgs = arguments; // eslint-disable-line prefer-rest-params
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

export default debounce;
