import $ from "jquery";

document.addEventListener("DOMContentLoaded", () => {
  if (!$(".mainPage").length) return;
  // @ts-ignore
  (typeof umami == 'object') && umami.track("main");
});
