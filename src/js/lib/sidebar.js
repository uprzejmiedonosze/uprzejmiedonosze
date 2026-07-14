// Sidebar nawigacyjny (_sidebar.html.twig): akordeon — naraz otwarta jedna sekcja.
// Rozwinięcie <details> zwija pozostałe w tym samym sidebarze. Sekcja bieżącej
// strony jest otwarta już z serwera (atrybut `open` w Twigu), więc na starcie nic
// nie ruszamy. Guard przez querySelectorAll => no-op na stronach bez sidebara.
document.querySelectorAll(".mpr-sidebar--nav").forEach((nav) => {
    const sections = nav.querySelectorAll("details.mpr-sidebar-section");
    sections.forEach((details) => {
        details.addEventListener("toggle", () => {
            if (!details.open) return;
            sections.forEach((other) => {
                if (other !== details) other.open = false;
            });
        });
    });
});
