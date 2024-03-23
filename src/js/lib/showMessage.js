
export default function showMessage(msg, timeout=1500) {
    $.mobile.loading("show", { html: msg, textVisible: true, textonly: true });
    window.setTimeout(function () {
        $.mobile.loading("hide");
    }, timeout);
}
  