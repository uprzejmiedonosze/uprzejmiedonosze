document.addEventListener("DOMContentLoaded", () => {

  if (!document.getElementsByClassName('faq').length)
    return

  let copyLink = document.createElement('a')
  copyLink.classList.add('copyLink')
  copyLink.title = 'Skopiuk link do sekcji'
  copyLink.innerText = ' (link)'

  document.querySelectorAll('h4[id]').forEach(
    node => node.appendChild(copyLink.cloneNode(true))
  )

  document.querySelectorAll('a.copyLink').addEventListener('click', function (e) {
    e.preventDefault();
    var copyText = `${window.location.origin}${window.location.pathname}#` + this.parentElement.id;
    navigator.clipboard.writeText(copyText);
  })
});
