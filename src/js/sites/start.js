document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".start-page")) return;
  // @ts-ignore
  (typeof ga == 'function') && ga("send", "event", { eventCategory: "pageshow", eventAction: "start" });
});
