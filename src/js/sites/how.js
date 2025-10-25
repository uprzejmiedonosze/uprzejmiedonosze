import { filterable } from "../lib/filterable"

document.addEventListener("DOMContentLoaded", () => {
  scrollTo(window.location.hash)
})

function scrollTo(hash) {
  if (!hash) return
  const hashElement = document.querySelector(hash)
  if (!hashElement) return
  
  window.location.hash = hash
  const elementTop = hashElement.getBoundingClientRect().top + window.pageYOffset - 60
  if (elementTop > 0) {
    window.scrollTo({ top: elementTop, behavior: 'smooth' })
  }
  
  document.querySelectorAll('.howto').forEach(el => el.classList.remove('highlight'))
  hashElement.classList.add('highlight')
}

// @ts-ignore
window._scrollToHowTo = scrollTo

filterable('filterable', 'filterable-list')

