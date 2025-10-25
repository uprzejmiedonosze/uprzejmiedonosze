document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".mainPage")) return;
  // @ts-ignore
  (typeof umami == 'object') && umami.track("main");
});
