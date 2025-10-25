import "lazysizes";

import "./lib/nodeListExt";
import "./lib/send";
import "./lib/sentry";

import "./sites/main";
import "./sites/application";
import "./sites/confirm-application";
import "./sites/faq";
import "./sites/gallery";
import "./sites/my-apps";
import "./sites/register";
import "./sites/regulations";
import "./sites/start";
import "./sites/statistics";
import "./sites/thank-you";
import "./sites/public-info";
import "./sites/how";
import "./sites/ask-for-status"
import "./sites/menu"
import "./sites/frequent-mistakes"
import makeDialog from "./lib/dialog";

document.querySelectorAll('.menu-button.right').forEach(button => {
    button.addEventListener('click', function() {
        this.classList.add('disabled');
    })
})

document.querySelectorAll('.button.cta').forEach(button => {
    button.addEventListener('click', function() {
        this.classList.add('disabled');
    })
})

document.querySelectorAll("textarea").forEach(textarea => {
    textarea.style.height = textarea.scrollHeight + "px";
    textarea.style.overflowY = "hidden";
    
    textarea.addEventListener("input", function () {
        this.style.height = "auto";
        this.style.height = this.scrollHeight + "px";
    })
})

document.addEventListener("DOMContentLoaded", () => {
    makeDialog()
})
