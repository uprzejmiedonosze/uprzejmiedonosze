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
  
  const dateHint = document.getElementById("dateHint")
  const datetimeInput = /** @type {HTMLInputElement} */ (document.getElementById("datetime"))
  const changeDatetimeLink = document.querySelector("a.changeDatetime")
  const dtFromPictureInput = /** @type {HTMLInputElement} */ (document.getElementById("dtFromPicture"))
  
  if (fromPicture) {
    if (dateTime !== "") {
      if (dateHint) {
        dateHint.textContent = "Data i godzina pobrana ze zdjęcia"
        dateHint.classList.add("hint")
      }
      if (datetimeInput) datetimeInput.setAttribute('readonly', 'true')
    }
    if (changeDatetimeLink) /** @type {HTMLElement} */ (changeDatetimeLink).style.display = 'block'
  } else {
    if (dateHint) {
      dateHint.textContent = "Podaj datę i godzinę zgłoszenia"
      dateHint.classList.add("hint")
    }
    if (datetimeInput) datetimeInput.removeAttribute('readonly')
    if (changeDatetimeLink) /** @type {HTMLElement} */ (changeDatetimeLink).style.display = 'none'
  }
  
  if (dtFromPictureInput) dtFromPictureInput.value = fromPicture ? '1' : '0'
  
  if (dt) {
    const formattedDt = dt.toFormat("yyyy-LL-dd'T'HH:mm");
    if (datetimeInput) {
      datetimeInput.value = formattedDt
      datetimeInput.classList.remove("error")
    }
    return formattedDt;
  }
  return null
}
