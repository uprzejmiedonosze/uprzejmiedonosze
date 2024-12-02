

function makeTabs() {
    const $tabs = document.querySelectorAll(".tabs > input")
    // @ts-ignore
    $tabs.addEventListener("click", switchTab)
}

function switchTab() {
    // @ts-ignore
    document.querySelectorAll(".tabcontent").display(false)

    // @ts-ignore
    document.querySelectorAll(".tablinks").removeClass("active")
  
    // @ts-ignore
    document.getElementById(`${this.id}-tab`).style.display = "block"
}

export default makeTabs
