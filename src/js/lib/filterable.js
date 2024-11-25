import delay from './delay'

/**
 * @param {string} inputId
 * @param {string|null} listId
 */
function filterable(inputId, listId) {
    const input = document.getElementById(inputId)
    if (!input) return
    input.addEventListener("input", delay(function () {
        // @ts-ignore
        const filter = this.value.toUpperCase()
        const ul = document.getElementById(listId ?? (inputId + '-list'))
        const lis = ul?.children || []
    
        for(let li of lis) {
            const filtertext = li.dataset.filtertext?.toUpperCase()
            li.style.display = (filtertext?.indexOf(filter) || 0) > -1? "": "none";
        }
    }, 300), false)
}

export default filterable