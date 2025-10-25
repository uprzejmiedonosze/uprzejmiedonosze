function copyToClipboard(selector) {
    selector = selector || 'dd#copy'
    const element = document.querySelector(selector)
    const txt = element ? element.textContent.trim() : ''
    navigator.clipboard.writeText(txt)
    const copyBtn = document.querySelector('a#copyBtn')
    if (copyBtn) copyBtn.textContent = 'Tekst skopiowany'
  }


window.copyToClipboard = copyToClipboard
