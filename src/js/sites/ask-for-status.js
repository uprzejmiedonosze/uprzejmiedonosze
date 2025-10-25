document.addEventListener("DOMContentLoaded", () => {
    if (!document.querySelector(".ask-for-status")) return;
  
    document.querySelectorAll('h3 > a').forEach(link => {
        link.addEventListener('click', e => {
            const target = /** @type {HTMLElement} */ (e.currentTarget)
            if (!target || !target.parentElement) return
            try {
                const apps = /** @type {HTMLElement} */ (target.parentElement.nextElementSibling)
                if (!apps) return
                const type = "text/html";
                const blob = new Blob([apps.innerHTML], {type});
                const data = [new ClipboardItem({[type]: blob})];
                navigator.clipboard.write(data).then(() => {
                    apps.style.opacity = '0.4'
                    target.classList.add('visited')
                    setTimeout(() => apps.style.opacity = '1', 80)
                })
            } catch(_e) {
                target.classList.add('error')
            }
        })
    })
  });
  