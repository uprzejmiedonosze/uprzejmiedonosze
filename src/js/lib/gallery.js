/* global ga */

function addToGallery(appId) {
  $.post("/api/api.html", { action: "addToGallery", id: appId }).done(
    function () {

      // dziekujemy.html.twig
      $("div.addToGallery").hide();
      $(".addedToGallery").show();

      // application-short.html.twig
      $("#" + appId + " .addToGallery").addClass("ui-disabled");
      (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "addToGallery" });
    }
  );
}
window.addToGallery = addToGallery;

function ignoreGallery() {
  $("div.addToGallery").hide();
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "js", eventAction: "ignoreGallery" });
}

window.ignoreGallery = ignoreGallery;
