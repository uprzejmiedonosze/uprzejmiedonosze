import { checkAddress, checkValue, checkCommentvalue, checkDateTimeValue } from "../lib/validation";

export function validateForm() {
  const formSubmit = document.getElementById("form-submit")
  if (formSubmit) formSubmit.classList.add("disabled")
  
  const plateIdInput = /** @type {HTMLInputElement} */ (document.getElementById("plateId"))
  var ret = checkValue(plateIdInput, 3);
  ret = checkDateTimeValue() && ret;
  ret = checkAddress() && ret;
  ret = checkImages() && ret;
  
  const categoryZero = /** @type {HTMLInputElement} */ (document.getElementById("0"))
  if (categoryZero && categoryZero.checked) {
    // if category == 0 then comment is mandatory
    ret = checkCommentvalue() && ret;
  }
  
  if (!ret) {
    const errorElement = document.querySelector(".error")
    if (errorElement) {
      errorElement.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  }
  
  if (formSubmit) formSubmit.classList.remove("disabled")
  return ret;
}

function checkImages() {
  let success = true;
  ['contextImage', 'carImage'].forEach(img => {
    const imgElement = document.querySelector(`.${img}Section img`)
    const sectionElement = document.querySelector(`.${img}Section`)
    if (imgElement && imgElement.getAttribute('src')?.startsWith('img/')) {
      if (sectionElement) sectionElement.classList.add("error")
      success = false;
    }
  })
  return success
}
