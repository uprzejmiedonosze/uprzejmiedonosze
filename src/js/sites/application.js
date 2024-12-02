import isIOS from "../lib/isIOS"
import makeTabs from "../lib/tabs"

document.addEventListener("DOMContentLoaded", () => {

  if (!document.getElementsByClassName('aplikacja').length)
    return

  makeTabs()
  const platform = isIOS() ? "ios": "android"

  // @ts-ignore
  document.getElementById(platform).checked = true
  // @ts-ignore
  document.getElementById(`${platform}-tab`).style.display = "block"
})
