import { checkValueRe } from "../lib/validation"

const nameElement = /** @type {HTMLInputElement} */ (document.getElementById("name"))
const addressElement = /** @type {HTMLInputElement} */ (document.getElementById("address"))
const edeliveryElement = /** @type {HTMLInputElement} */ (document.getElementById("edelivery"))

function validateRegisterForm() {
  let ret = checkValueRe(nameElement, /^(\S{2,5}\s)?\S{3,20}\s[\S -]{3,40}$/i)
  const addressCheck = checkValueRe(addressElement, /^.{3,50}\d.{3,40}\D$/i)
  ret = addressCheck && ret
  ret = checkValueRe(edeliveryElement, /(^[A-Z]{2}:[A-Z]{2}-(\d{5}-){2}[A-Z]{5}-\d{2})$|^$/i) && ret
  
  if (!ret) {
    const errorElement = document.querySelector(".error");
    if (errorElement) {
      errorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  if (!addressCheck) {
    const addressLabel = document.querySelector('label[for="address"]');
    if (addressLabel) {
      addressLabel.textContent = 'Poprawny format to: "Ulica numer domu/mieszkania, Miasto"';
    }
  }

  return ret;
}

document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".register")) return;

  if (nameElement) {
    nameElement.addEventListener("change", function () {
      nameElement.classList.remove("error");
    });
  }

  if (addressElement) {
    addressElement.addEventListener("change", function () {
      addressElement.classList.remove("error");
    });
  }

  const submitButton = document.getElementById("register-submit");
  if (submitButton) {
    submitButton.addEventListener("click", function () {
      if (validateRegisterForm()) {
        const form = /** @type {HTMLFormElement} */ (document.getElementById("register-form"));
        if (form) form.submit();
      } else {
        submitButton.classList.remove('disabled');
      }
    });
  }
});
