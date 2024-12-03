/**
 * @param {string} msg
 */
export function message(msg) {
    const toast = document.getElementById("toast")
    if (!toast) return
    // @ts-ignore
    toast.innerHTML = msg
    toast.classList.remove('error')
    toast?.classList.add("show")
}


/**
 * @param {string} msg
 */
export function toast(msg) {
    const toast = document.getElementById("toast")
    if (!toast) return
    // @ts-ignore
    toast.innerHTML = msg
    toast.classList.remove('error')
    show(toast, 1500)
}

/**
 * @param {string} msg
 */
export function error(msg) {
    const toast = document.getElementById("toast")
    if (!toast) return
    // @ts-ignore
    toast.innerHTML = `<h3>Błąd!</h3>${msg}`
    toast.classList.add('error')
    show(toast, 10000)
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
