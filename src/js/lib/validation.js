import $ from "jquery"
import { DateTime } from "luxon";

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

export const checkValueRe = function(item, regex) {
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
  $("#comment").addClass("error");
  return false
}

export const checkDateTimeValue = function () {
  const dt = DateTime.fromISO($('#datetime')?.val() + "")
  const result = dt.isValid && dt < DateTime.now()
  !result && $('#datetime').addClass("error")
  return result
}