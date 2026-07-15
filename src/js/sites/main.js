document.addEventListener("DOMContentLoaded", () => {
  if (!document.querySelector(".mainPage")) return;
  _paq.push(['trackEvent', 'Navigation', 'main']);

  initDemo();
});

// "Zobacz UD w akcji" — auto-cycling demo. Progresuje ~4.5 s na krok, po czym
// przełącza się na następny (z zawijaniem). Klik na krok przeskakuje ręcznie.
function initDemo() {
  const root = document.querySelector("[data-demo]");
  if (!root) return;

  const steps = [...root.querySelectorAll("[data-demo-step]")];
  const shots = [...root.querySelectorAll("[data-demo-shot]")];
  const bars = steps.map(s => s.querySelector(".mpr-demo-progress-bar"));
  const caption = root.querySelector("[data-demo-caption]");
  if (!steps.length || steps.length !== shots.length) return;

  const captions = [
    "Fotografujesz kontekst wykroczenia i pojazd – aplikacja prowadzi Cię krok po kroku.",
    "Numer rejestracyjny, data i adres uzupełniają się automatycznie ze zdjęcia.",
    "Podgląd treści trafiającej do SM/na policję, zanim je wyślesz.",
  ];

  const DURATION = 4500; // ms na krok
  let current = 0;
  let progress = 0; // 0..1 w obrębie bieżącego kroku
  let last = null;
  let raf = null;

  function render() {
    steps.forEach((s, i) => s.classList.toggle("is-active", i === current));
    shots.forEach((s, i) => s.classList.toggle("is-active", i === current));
    bars.forEach((bar, i) => {
      if (!bar) return;
      const w = i < current ? 1 : i > current ? 0 : progress;
      bar.style.width = (w * 100) + "%";
    });
    if (caption) caption.textContent = captions[current] || "";
  }

  function tick(now) {
    if (last == null) last = now;
    progress += (now - last) / DURATION;
    last = now;
    if (progress >= 1) {
      progress = 0;
      current = (current + 1) % steps.length;
    }
    render();
    raf = requestAnimationFrame(tick);
  }

  function setStep(i) {
    current = i;
    progress = 0;
    last = null;
    render();
  }

  steps.forEach((step, i) => {
    step.addEventListener("click", () => setStep(i));
  });

  // Respektuj preferencję ograniczenia animacji — bez auto-cyklu, tylko klik.
  const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  render();
  if (!reduce) raf = requestAnimationFrame(tick);
}
