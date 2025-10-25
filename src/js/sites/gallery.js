async function tumblr() {
  try {
    const response = await fetch("https://galeria.uprzejmiedonosze.net/api/read/json?num=30");
    const text = await response.text();
    // Remove JSONP callback wrapper
    const jsonText = text.replace(/^[^{]*/, '').replace(/[^}]*$/, '');
    const data = JSON.parse(jsonText);
    
    data.posts.forEach(function (post) {
      var postHtml = "";

      switch (post.type) {
        case "regular":
          if (post["regular-title"]) {
            postHtml += "<h3>" + post["regular-title"] + "</h3>";
          }
          postHtml += "<p>" + post["regular-body"] + "</p>";
          break;
        case "link":
          postHtml =
            "<h3><a href='" +
            post["link-url"] +
            "'>" +
            post["link-text"] +
            "</a></h3>";
          break;
        case "quote":
          postHtml = post["quote-text"];
          break;
        case "photo":
          postHtml =
            '<div class="galleryItem"><a href=\'' +
            post["url-with-slug"] +
            "'><img src='" +
            post["photo-url-500"] +
            "'></a>";
          if (post["photo-caption"]) {
            postHtml += "<p>" + post["photo-caption"] + "</p>";
          }
          postHtml += "</li>";
          break;
      }

      const loader = document.querySelector("div.loader");
      if (loader) loader.style.display = 'none';
      
      const tumblrPosts = document.querySelector("div.tumblr_posts");
      if (tumblrPosts) {
        tumblrPosts.insertAdjacentHTML('beforeend', postHtml);
      }
    });
  } catch (error) {
    console.error('Error loading gallery:', error);
  }
}

if (document.querySelector(".gallery")) {
  document.addEventListener("DOMContentLoaded", function () {
    if (document.querySelector("div.tumblr_posts")) {
      tumblr();
    }
  });
}
