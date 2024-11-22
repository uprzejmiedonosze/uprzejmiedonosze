import $ from "jquery"

$(document).on("pageshow", function () {
    if (!$(".ask-for-status").length) return;
  
    $('h3 > a').on('click', e => {
        const link = e.currentTarget
        if (!link) return
        try {
            const apps = link?.parentElement?.nextElementSibling
            const type = "text/html";
            const blob = new Blob([apps.innerHTML], {type});
            const data = [new ClipboardItem({[type]: blob})];
            navigator.clipboard.write(data).then(() => {
                apps.style.opacity = '0.4'
                $(link).addClass('visited')
                setTimeout(() => apps.style.opacity = '1', 80)
            })
        } catch(_e) {
            $(link).addClass('error')
        }
    })
  });
  