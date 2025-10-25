document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".start-page")) return;
  // @ts-ignore
  (typeof umami == 'object') && umami.track("start");
});
