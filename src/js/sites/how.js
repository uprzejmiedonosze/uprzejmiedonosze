function activate(howto, makeActive=true) {
  const content = howto.getElementsByClassName("content")[0]
  if (makeActive) {
    //content.style.maxHeight = content.scrollHeight + "px";
    howto.classList.add("active")
    return
  }
  //content.style.maxHeight = null
  howto.classList.remove("active")
  
}

// @ts-ignore
window._activateHowTo = (id) => {
  const howtos = document.getElementsByClassName("howto") || [];
  Array.from(howtos).forEach(howto => {
    activate(howto, id == howto.id)
  })
  return false
}

function handleCardClick() {
  const howtos = document.getElementsByClassName("howto") || [];
  const hash = window.location.hash

  Array.from(howtos).forEach(howto => {
    if (hash == `#${howto.id}`) activate(howto)
    
    const links = howto.getElementsByTagName("a") || []
    Array.from(links).forEach(link =>
      link.addEventListener("click", 
        event => event.stopPropagation()))

    howto.addEventListener("click", () => { 
      const wasActive = howto.classList.contains("active")
      window.location.hash = wasActive ? "" : howto.id
      activate(howto, !wasActive)
    })
  })
}

$(handleCardClick);
