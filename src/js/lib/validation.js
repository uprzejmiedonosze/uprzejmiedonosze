import { DateTime } from "luxon"
import { error, warning } from "./toast"

export const checkAddress = function () {
  const lokalizacjaInput = /** @type {HTMLInputElement} */ (document.getElementById("lokalizacja"))
  const addressInput = /** @type {HTMLInputElement} */ (document.getElementById("address"))
  
  const textAddress = (lokalizacjaInput?.value || "").trim()
  const jsonAddress = addressInput?.value || ""

  var ret = textAddress.length > 10;
  var address = JSON.parse(jsonAddress)
  ret = address.city?.length > 2 && ret
  ret = address.lat > 0 && ret
  ret = address.lng > 0 && ret
  if (!ret && textAddress.length > 0) {
    const addressHint = document.getElementById("addressHint")
    if (addressHint) {
      addressHint.textContent = "Podaj adres lub wskaż go na mapie. Ew. uwagi dotyczące lokalizacji napisz w polu komentarz poniżej"
      addressHint.classList.add("hint")
    }
  }

  if (!ret && lokalizacjaInput) lokalizacjaInput.classList.add("error")
  return ret;
};

export const checkValue = function (item, minLength) {
  if (!item) return false
  const len = item.value.trim().length
  if (len > minLength)
    return true
  item.classList.add("error")
  return false
}

export const checkValueRe = function (item, regex) {
  if (!item) return false
  if (item.value.trim().match(regex))
    return true
  item.classList.add("error")
  return false
}

export const checkCommentvalue = function () {
  const commentInput = /** @type {HTMLTextAreaElement} */ (document.getElementById("comment"))
  if (!commentInput) return false
  
  let comment = (commentInput.value || "").trim()
  comment = comment.replace(/^Pojazd (prawdopodobnie )?marki \w+[\s-]?\w*\.?/ig, '').trim()
  if (comment.length > 10)
    return true
  commentInput.classList.add("error")
  commentInput.placeholder = "Podaj rodzaj wykroczenia z listy poniżej, albo opisz je w tym polu"
  return false
}

export function bindSoftCommentValidation() {
  const commentInput = /** @type {HTMLTextAreaElement} */ (document.getElementById('comment'))
  if (commentInput) {
    commentInput.addEventListener('focusout', function () {
      const comment = this.value ?? ''
      const witnessElement = /** @type {HTMLInputElement} */ (document.getElementById('witness'))
      const witnessChecked = witnessElement?.checked
      if (witnessChecked) return
      const driver = comment.search(/(?:^|[^A-Za-z0-9_])kier\w*/i) >= 0
      let warningMsg = null
      if (driver)
        warningMsg = 'Wspominasz kierowcę w komentarzu.'
      const witness = comment.search(/(?:^|[^A-Za-z0-9_])[sś]wiadk\w*/i) >= 0
      if (witness)
        warningMsg = 'Używasz słowa „świadek” w komentarzu.'
      const mr = comment.search(/(?:^|[^A-Za-z0-9_])pani?/i) >= 0
      if (mr)
        warningMsg = 'Używasz słowa „pan/i” w komentarzu.'
      if (warningMsg) {
        warning(`<p>${warningMsg}</p><a href="#statements">Sprawdź opcję „świadek momentu parkowania”</a>`)
      }
    })
  }
}

export const checkDateTimeValue = function () {
  const datetimeInput = /** @type {HTMLInputElement} */ (document.getElementById('datetime'))
  const dt = DateTime.fromISO(datetimeInput?.value || "")
  if (dt > DateTime.now()) {
    if (datetimeInput) datetimeInput.classList.add("error")
    error("Data nie może być z przyszłości")
    return false
  }
  const sevenMonthsAgo = DateTime.now().minus({ months: 7 })
  if (dt < sevenMonthsAgo) {
    if (datetimeInput) datetimeInput.classList.add("error")
    error("Wykroczenie starsze niż 7 miesięcy. SM/Policja nie zdąży zareagować!")
    return false
  }
  return dt.isValid
}