

function makeDialog() {
    const dialogs = document.querySelectorAll('dialog')

    dialogs.forEach(dialog =>
        dialog.addEventListener('mousedown', event => {
            if (event.target === event.currentTarget) {
                // @ts-ignore
                event.currentTarget?.close()
            }
        })
    )

    const popups = document.querySelectorAll('a[data-rel=popup]');
    popups.forEach(popup => {
        popup.addEventListener("click", function () {
            const id = this.hash.substring(1)
            // @ts-ignore
            document.getElementById(id)?.showModal()
        })
    })
}

export default makeDialog
