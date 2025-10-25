document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".mainPage")) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "index" });
});
