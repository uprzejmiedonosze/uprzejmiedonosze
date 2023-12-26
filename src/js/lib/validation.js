import { DateTime } from "luxon";

export const checkAddress = function (where) {
  var ret = where.val().trim().length > 10;

  // checking this only on new-application page (not on registration where adddress field name differs)
  if (where.selector == "#lokalizacja") {
    ret = $("#locality").val().trim().length > 2 && ret;
    ret =
      !!$("#latlng")
        .val()
        .trim()
        .match(/\d\d\.\d+,\d\d\.\d+/) && ret;

    if (!ret && where.val().trim().length > 0) {
      $("#addressHint").text(
        "Podaj adres lub wskaż go na mapie. Ew. uwagi dotyczące lokalizacji napisz w polu komentarz poniżej"
      );
      $("#addressHint").addClass("hint");
    }
  }
  // register form
  if (where.selector == "#address") {
    // registration address must contain flat no
    ret = /\d/.test(where.val()) && ret;
  }

  !ret && where.addClass("error");

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
  let comment = $("#comment").val().trim()
  comment = comment.replace(/^Pojazd (prawdopodobnie )?marki \w+[ -]\w*/, '').trim()
  if (comment.length > 10)
    return true
  $("#comment").addClass("error");
  return false
}

export const checkDateTimeValue = function () {
  const dt = DateTime.fromISO($('#datetime').val())
  const result = !dt.invalid && dt < DateTime.now()
  !result && $('#datetime').addClass("error")
  return result
}