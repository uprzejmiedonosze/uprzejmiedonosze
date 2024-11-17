import debounce from '../lib/debounce';

class Nav {
  constructor(nav) {
    this.nav = nav;
    this.menu = nav.querySelector('.js-nav-menu');
    this.button = nav.querySelector('.js-nav-button');
    this.items = [...nav.querySelectorAll('.js-nav-item')];
    this.isNavActive = false;

    this.handleResize = debounce(() => {
      if(window.innerWidth > 767) this.removeActiveClasses();
    }, 400);
  }

  init() {
    this.items[0].classList.add('active');

    window.addEventListener('resize', this.handleResize);
    this.button.addEventListener('click', () => this.toggleNav());
    this.items.map((item) => item.addEventListener('click', () => this.removeActiveClasses()));
  }

  toggleNav() {
    if(this.isNavActive) {
      this.nav.classList.remove('active');
    } else {
      this.nav.classList.add('active');
    }

    this.isNavActive = !this.isNavActive;
  }

  removeActiveClasses() {
    this.nav.classList.remove('active');
    this.isNavActive = false;
  }

}

function navigation() {
  const navigation = document.querySelector('.js-nav');
  if (!navigation) {
    console.error("no navigation element found", navigation)
    return
  }
  new Nav(navigation).init();
};

document.addEventListener("DOMContentLoaded", () => {
    navigation()
})