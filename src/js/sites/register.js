import $ from "jquery"

import { checkValueRe } from "../lib/validation"

const $name = $("#name")
const $address = $("#address")
const $edelivery = $("#edelivery")

function validateRegisterForm() {
  let ret = checkValueRe($name, /^(\S{2,5}\s)?\S{3,20}\s[\S -]{3,40}$/i)
  const addressCheck = checkValueRe($address, /^.{3,50}\d.{3,40}\D$/i)
  ret = addressCheck && ret
  ret = checkValueRe($edelivery, /(^[A-Z]{2}:[A-Z]{2}-(\d{5}-){2}[A-Z]{5}-\d{2})$|^$/i) && ret
  
  if (!ret)
    $(window).scrollTop(($(".error")?.offset()?.top ?? 0) - 100);

  if (!addressCheck)
    $('label[for="address"]').text(
      'Poprawny format to: "Ulica numer domu/mieszkania, Miasto"')

  return ret;
}

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".register").length) return;

  $name.on("change", function () {
    $name.removeClass("error");
  });

  $address.on("change", function () {
    $address.removeClass("error");
  });

  $("#register-submit").click(function () {
    if (validateRegisterForm()) {
      $("#register-form").submit();
    } else {
      $("#register-submit").removeClass('disabled')
    }
  });
});
