document.addEventListener("DOMContentLoaded", () => {
  const cardsContainers = document.querySelectorAll('.czesto-popelniane-bledy .cards');
  if (cardsContainers.length===0) return;

  function scrollTo(figure) {
    figure.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
      inline: 'start'
    });
  }

  function setClasses(ev) {
    const arrowLeft = ev.target.querySelector(".left");
    const arrowRight = ev.target.querySelector(".right");
    if (ev.target.scrollLeft > ev.target.scrollWidth * 0.3) {
      arrowLeft.classList.remove('hidden');
      arrowRight.classList.add('hidden');
    } else {
      arrowLeft.classList.add('hidden');
      arrowRight.classList.remove('hidden');
    }
  };

  cardsContainers.forEach(container => {
    setClasses({ target: container });
    container.addEventListener("scroll", setClasses);

    container.querySelector(".right").addEventListener("click", (ev) => {
     scrollTo(container.querySelectorAll("figure")[1]);
    });
    container.querySelector(".left").addEventListener("click", (ev) => {
     scrollTo(container.querySelectorAll("figure")[0]);
    });
  });

});
