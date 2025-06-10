import $ from "jquery"
import { DateTime } from "luxon"
import { error, warning } from "./toast"

export const checkAddress = function () {
  const textAddress = ($("#lokalizacja")?.val() + "").trim()
  const jsonAddress = $("#address")?.val() + ""

  var ret = textAddress.length > 10;
  var address = JSON.parse(jsonAddress)
  ret = address.city?.length > 2 && ret
  ret = address.lat > 0 && ret
  ret = address.lng > 0 && ret
  if (!ret && textAddress.length > 0) {
    $("#addressHint").text(
      "Podaj adres lub wskaż go na mapie. Ew. uwagi dotyczące lokalizacji napisz w polu komentarz poniżej"
    );
    $("#addressHint").addClass("hint");
  }

  !ret && $("#lokalizacja").addClass("error");
  return ret;
};

export const checkValue = function (item, minLength) {
  const len = item.val().trim().length
  if (len > minLength)
    return true
  item.addClass("error")
  return false
}

export const checkValueRe = function (item, regex) {
  if (item.val().trim().match(regex))
    return true
  item.addClass("error")
  return false
}

export const checkCommentvalue = function () {
  let comment = ($("#comment")?.val() + "")?.trim()
  comment = comment.replace(/^Pojazd (prawdopodobnie )?marki \w+[\s-]?\w*\.?/ig, '').trim()
  if (comment.length > 10)
    return true
  $("#comment").addClass("error")
  $("#comment").attr("placeholder", "Podaj rodzaj wykroczenia z listy poniżej, albo opisz je w tym polu")
  return false
}

export function bindSoftCommentValidation() {
  $('#comment')
    .on('focusout', function () {
      // @ts-ignore
      const comment = this.value ?? ''
      // @ts-ignore
      const witnessChecked = document.getElementById('witness')?.checked
      if (witnessChecked) return
      const driver = comment.search(/(?:^|[^A-Za-z0-9_])kieruj\w*/i) >= 0
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

export const checkDateTimeValue = function () {
  const dt = DateTime.fromISO($('#datetime')?.val() + "")
  if (dt > DateTime.now()) {
    $('#datetime').addClass("error")
    error("Data nie może być z przyszłości")
    return false
  }
  const eightMonthsAgo = DateTime.now().minus({ months: 10 })
  if (dt < eightMonthsAgo) {
    $('#datetime').addClass("error")
    error("Wykroczenie starsze niż 10 miesięcy. SM/Policja nie zdąży zareagować!")
    return false
  }
  return dt.isValid
}