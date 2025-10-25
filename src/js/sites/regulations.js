document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".przepisy")) return;
  const hash = window.location.hash;
  if (!hash) return
  const hashElement = document.querySelector(hash);
  if (hashElement) {
    setTimeout(function () {
      hashElement.scrollIntoView({ behavior: 'smooth' });
    }, 100);
  }
});
