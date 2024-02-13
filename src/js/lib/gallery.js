/* global ga */

function addToGallery(appId) {
  $.ajax({
    type: 'PATCH',
    url: `/api/app/${appId}/gallery/add`,
    contentType: false,
    processData: false,
  }).done( function () {
    // dziekujemy.html.twig
    $("div.addToGallery").hide();
    $(".addedToGallery").show();

    // application-short.html.twig
    $("#" + appId + " .addToGallery").addClass("ui-disabled");
    (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "addToGallery" });
  });
}
window.addToGallery = addToGallery;

function ignoreGallery() {
  $("div.addToGallery").hide();
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "ignoreGallery" });
}

window.ignoreGallery = ignoreGallery;
