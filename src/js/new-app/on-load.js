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
    $(".contextImageSection label b").text(
      // @ts-ignore
      $(e.target).attr("data-contextImage-hint")
    )
    $(".carImageSection label b").text(
      // @ts-ignore
      $(e.target).attr("data-carImage-hint")
    )

    validateExtensions()
  });

  // initial validation on load
  validateExtensions()

  if (window.File && window.FileReader && window.FormData) {
    $(document).on("change", ".image-upload input", function (e) {
      /** @var {'contextImage' | 'carImage' | 'thirdImage'} imagesOrder */
      if(e.target.files.length === 1) {
        return checkFile(e.target.files[0], e.target.id);
      }
      let imagesOrder = ['contextImage', 'carImage', 'thirdImage'].reverse()
      // @ts-ignore
      checkFile(e.target.files[0], imagesOrder.pop());
      if(e.target.files.length > 1)
        // @ts-ignore
        checkFile(e.target.files[1], imagesOrder.pop());
      if(e.target.files.length > 2) 
        // @ts-ignore
        checkFile(e.target.files[2], imagesOrder.pop());
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


  $(".image-upload img").on("load", function () {
    $(this).show();
  });
};


function validateExtensions() {
  // @ts-ignore
  const selectedCategory = document?.querySelector('input[name="category"]:checked')?.value || '0'

  const $extensions = document?.getElementById('extensions')
  const $labels = [... ($extensions?.querySelectorAll('label') || [])]

  $labels.forEach(e => e.classList.remove('disabled'))

  const matchingExtension = document.querySelector(`#ex${selectedCategory}`)
  const matchingExtensionLabel = document.querySelector(`#ex${selectedCategory} + label`)
  
  if (matchingExtension) {
    // @ts-ignore
    matchingExtension.checked = false
    matchingExtensionLabel?.classList.add('disabled')
  }

  if (selectedCategory !== '0')
    document?.getElementById('comment')?.classList.remove('error')
}