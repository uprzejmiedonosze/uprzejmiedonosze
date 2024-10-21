
export function showMessage(msg) {
    $.mobile.loading("show", {
        html: msg,
        textVisible: true,
        textonly: true
    });
    window.setTimeout(function () {
        $.mobile.loading("hide");
    }, 1500);
}

/**
 * @param {string} msg
 */
export function showError(msg) {
    $.mobile.loading("show", {
        html: `<b style="color: red;">Błąd: ${msg}</b>`,
        textVisible: true,
        textonly: true
    });
    window.setTimeout(function () {
        $.mobile.loading("hide");
    }, 7000);
}