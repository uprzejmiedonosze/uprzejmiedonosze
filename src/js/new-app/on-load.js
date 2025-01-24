import $ from "jquery"

import { checkFile } from "./images";
import { validateForm } from "./validate-form";

export const initHandlers = (map) => {
  $("#msisdn").on("change", function () {
    $("#msisdn").removeClass("error");
  });

  $("#plateId").on("change", function () {
    $("#plateId").removeClass("error");
    $("#plateImage").hide();
    $("#recydywa").hide();
  });

  $("#comment").on("change", function () {
    $("#comment").removeClass("error");
  });

  $("#datetime").on("change", function () {
    $("#datetime").removeClass("error");
  });

  $("#category").on("change", function (e) {
    $(".contextImageSection p.pictureHint").text(
      $(e.target).attr("data-contextImage-hint")
    )
    $(".carImageSection p.pictureHint").text(
      $(e.target).attr("data-carImage-hint")
    )

    validateExtensions()
  });

  // initial validation on load
  validateExtensions()

  if (window.File && window.FileReader && window.FormData) {
    $(document).on("change", ".image-upload input", function (e) {
      /** @var {'contextImage' | 'carImage' | 'thirdImage'} imagesOrder */
      let imagesOrder = ['contextImage', 'carImage', 'thirdImage'].reverse()
      checkFile(e.target.files[0], e.target.id);
      imagesOrder = imagesOrder.filter(i => i !== e.target.id)
      if(e.target.files.length > 1) {
        checkFile(e.target.files[1], imagesOrder.pop());
      }
      if(e.target.files.length > 2) {
        checkFile(e.target.files[2], imagesOrder.pop());
      }
    });
  }

  $("#resizeMap").click(function () {
    $("#locationPicker").toggleClass("locationPickerBig")
    $("#resizeMap").toggleClass("ui-icon-arrowsin")
    map.resize()
  });

  $("#form-submit").click(function () {
    if (validateForm()) {
      $("#form-submit").addClass('disabled')
      $("#form").submit();
    }
  });

  $("a.changeDatetime").click(function () {
    $("a.changeDatetime").hide();
    $("#datetime").removeAttr('readonly')
  });

  showHidePictureHints($(".contextImageSection"));
  showHidePictureHints($(".carImageSection"));

  $(".image-upload img").on("load", function () {
    $(this).show();
    showHidePictureHints($(this).parent().parent());
  });
};

function showHidePictureHints(context) {
  const placeholder = context?.find("img")?.attr("src") == 'img/fff-1.png'
  const recydywaVisible = context.find('#recydywa:visible').length

  context.find("p.pictureHint").hide()

  if (!placeholder && !recydywaVisible)
    context.find("p.pictureHint").show()
}

function validateExtensions() {
  const selectedCategory = document?.querySelector('input[name="category"]:checked')?.value || '0'

  const $extensions = document?.getElementById('extensions')
  const $labels = [... ($extensions?.querySelectorAll('label') || [])]

  $labels.forEach(e => e.classList.remove('disabled'))

  const matchingExtension = document.querySelector(`#ex${selectedCategory}`)
  const matchingExtensionLabel = document.querySelector(`#ex${selectedCategory} + label`)
  
  if (matchingExtension) {
    matchingExtension.checked = false
    matchingExtensionLabel?.classList.add('disabled')
  }
}