import $ from "jquery"
import { DateTime } from "luxon";

export function setDateTime(dateTime, fromPicture = true) {
  let dt = dateTime;
  if (typeof dateTime === "string") {
    dt = DateTime.fromFormat(dateTime, "yyyy:MM:dd HH:mm:ss");
    if (dt.invalid) dt = DateTime.fromFormat(dateTime, "yyyy:MM:dd HH:mm");
    if (dt.invalid) dt = DateTime.fromISO(dateTime);
    if (dt.invalid) {
      dt = null
      fromPicture = false
    }
  }
  if (fromPicture) {
    if (dateTime !== "") {
      $("#dateHint").text("Data i godzina pobrana ze zdjęcia");
      $("#dateHint").addClass("hint");
      $("#datetime").attr('readonly', 'true');
    }
    $("a.changeDatetime").show();
  } else {
    $("#dateHint").text("Podaj datę i godzinę zgłoszenia");
    $("#dateHint").addClass("hint");
    $("#datetime").removeAttr('readonly')
    $("a.changeDatetime").hide();
  }
  $("#dtFromPicture").val(fromPicture ? 1 : 0);
  if (dt) {
    const formattedDt = dt.toFormat("yyyy-LL-dd'T'HH:mm");
    $("#datetime").val(formattedDt);
    return formattedDt;
  }
  return null
}
