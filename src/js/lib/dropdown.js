
function makeDropdown() {
    const dropdowns = document.querySelectorAll(".dropdown > button")
    for (let dropdown of dropdowns)
        dropdown.addEventListener("click", event => {
            const dropdownContent = event?.target?.parentElement?.getElementsByClassName('dropdown-content').item(0)
            dropdownContent.classList.toggle('show')
        })
}


function dropdownClick(event) {
    if (event.target.matches('.dropdown-button')) return
    const dropdowns = document.querySelectorAll(".dropdown-content.show")
    for (let dropdown of dropdowns)
        dropdown.classList.remove('show');
}

window.addEventListener("click", dropdownClick)

export default makeDropdown
