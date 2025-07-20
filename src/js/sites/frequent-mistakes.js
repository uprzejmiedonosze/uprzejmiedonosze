document.addEventListener("DOMContentLoaded", () => {
  const cardsContainer = document.querySelector('.czesto-popelniane-bledy .cards');
  if (!cardsContainer) return;

  const arrowRight = cardsContainer.querySelector('.right');
  const arrowLeft = cardsContainer.querySelector('.left');
  const figures = cardsContainer.querySelectorAll('figure');

  if (!arrowRight || !arrowLeft || figures.length === 0) return;
  
  function scrollTo(index) {
    const figure = figures[index];
    if (!figure) return;
    figure.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
      inline: 'start'
    });
  }

  function setClasses(){
    if (cardsContainer.scrollLeft > cardsContainer.scrollWidth * 0.3) {
      arrowLeft.classList.remove('hidden');
      arrowRight.classList.add('hidden');
    } else {
      arrowLeft.classList.add('hidden');
      arrowRight.classList.remove('hidden');
    }
  };

  setClasses();

  cardsContainer.addEventListener("scroll", setClasses);

  arrowRight.addEventListener("click", () => {
    scrollTo(1);
  });
  arrowLeft.addEventListener("click", () => {
    scrollTo(0);
  });
});
