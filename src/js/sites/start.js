import $ from "jquery"

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".start-page").length) return;
  // @ts-ignore
  (typeof umami == 'object') && umami.track("start");
});
