import { DateTime } from "luxon";

export function setDateTime(dateTime, fromPicture = true) {
  let dt = dateTime;
  if (typeof dateTime === "string") {
    dt = DateTime.fromISO(dateTime);
    if (dt.invalid) {
      dt = null
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
    const formattedDt = dt.toFormat("yyyy-LL-dd'T'HH:mm:ss");
    $("#datetime").val(formattedDt);
    return formattedDt;
  }
  return null
}
