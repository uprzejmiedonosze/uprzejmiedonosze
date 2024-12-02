
// @ts-ignore
NodeList.prototype.addEventListener = function(eventName, callback, useCapture) {
  for (let node of this)
    node.addEventListener(eventName, callback, useCapture);
}

// @ts-ignore
NodeList.prototype.addClass = function(cls) {
  for (let node of this)
    // @ts-ignore
    node.classList.add(cls)
}

// @ts-ignore
NodeList.prototype.removeClass = function(cls) {
  for (let node of this)
    // @ts-ignore
    node.classList.remove(cls)
}

// @ts-ignore
NodeList.prototype.display = function (show) {
  for (let node of this)
    // @ts-ignore
    node.style.display = (show) ? "block" : "none";
}