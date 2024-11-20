function closeMenu()  {
    // @ts-ignore
    document.getElementById("toggle").checked = false
}

window.addEventListener("keydown", e => e.key === 'Escape' && closeMenu())


document.getElementById("courtain")?.addEventListener("click", closeMenu)
