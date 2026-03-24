/**
 * @param {string} msg
 */
export function message(msg) {
    const toast = document.getElementById("toast")
    if (!toast) return
    toast.textContent = msg
    toast.classList.remove('error')
    toast?.classList.add("show")
}


/**
 * @param {string} msg
 */
export function toast(msg) {
    const toast = document.getElementById("toast")
    if (!toast) return
    toast.textContent = msg
    toast.classList.remove('error')
    show(toast, 1500)
}

/**
 * @param {string} msg
 */
export function error(msg) {
    const toast = document.getElementById("toast")
    if (!toast) return
    toast.innerHTML = ''
    const h3 = document.createElement('h3')
    h3.textContent = 'Błąd!'
    const span = document.createElement('span')
    span.textContent = msg
    toast.appendChild(h3)
    toast.appendChild(span)
    toast.classList.add('error')
    show(toast, 10000)
}

/**
 * @param {string} msg
 * @param {string|null} [linkText]
 * @param {string|null} [linkHref]
 */
export function warning(msg, linkText = null, linkHref = null) {
    const toast = document.getElementById("toast")
    if (!toast) return
    toast.innerHTML = ''
    const p = document.createElement('p')
    p.textContent = msg
    toast.appendChild(p)
    if (linkText && linkHref) {
        const a = document.createElement('a')
        a.href = linkHref
        a.textContent = linkText
        toast.appendChild(a)
    }
    toast.classList.add('warning')
    show(toast, 6000)
}

/**
 * @param {HTMLElement} toast
 * @param {number} ms
 */
function show(toast, ms) {
    toast?.classList.add("show")
    setTimeout(() => {
        toast?.classList.remove("show")
    }, ms);
}
