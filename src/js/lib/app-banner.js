// nazwę cookie czyta też HtmlMiddleware (general.showAppBanner)
const COOKIE = "appBannerClicked=1; path=/; max-age=31536000; SameSite=Lax"

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".js-app-banner").forEach(banner => {
    banner.querySelectorAll(".js-app-banner-action").forEach(action => {
      action.addEventListener("click", () => {
        document.cookie = COOKIE
        // @ts-ignore
        banner.style.display = "none"
      })
    })
  })
})
