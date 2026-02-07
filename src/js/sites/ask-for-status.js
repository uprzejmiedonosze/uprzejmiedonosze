document.addEventListener("DOMContentLoaded", () => {
    if (!document.querySelector(".ask-for-status")) return;
  
    document.querySelectorAll('h3 > a').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const target = /** @type {HTMLElement} */ (e.currentTarget)
            if (!target || !target.parentElement) return
            try {
                const apps = /** @type {HTMLElement} */ (target.parentElement.nextElementSibling)
                if (!apps) return
                const htmlBlob = new Blob([apps.innerHTML], {type: "text/html"});
                const textBlob = new Blob([apps.innerText], {type: "text/plain"});
                const data = [
                  new ClipboardItem({
                    "text/html": htmlBlob,
                    "text/plain": textBlob
                  })
                ];
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
  
