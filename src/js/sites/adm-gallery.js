import Api from '../lib/Api'

$(document).on("pageshow", function () {
  if (!$(".galleryAdmin").length) return;

  function scrollNext() {
    const next = $('.galleryItem:not(.decision)')[0]
    $([document.documentElement, document.body]).animate({
      scrollTop: next.offsetTop - 70
    })
    $(next).addClass('next')
  }

  scrollNext()

  document.addEventListener('keypress', ({code}) => {
    const appId = $('.galleryItem.next').attr('id')
    if (code === 'KeyD' || code === 'Period') {
      window._moderateApp(appId, true)
    }
    if (code === 'Escape' || code === 'KeyQ' || code === 'Minus' || code === 'Comma') {
      window._moderateApp(appId, false)
    }
  });

  window._moderateApp = async (appId, decision) => {
    $("#" + appId).removeClass('next')
    $("#" + appId).addClass("decision")
    scrollNext()

    try {
      const api = new Api(`/api/app/${appId}/gallery/moderate/${decision}`)
      await api.patch()
      $("#" + appId).addClass("blur")
    } catch (e) {
      return false;
    }
  }
})
