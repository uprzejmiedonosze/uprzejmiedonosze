import $ from "jquery";
// @ts-ignore
window.$ = $;
// @ts-ignore
window.jQuery = $;

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
import "./sites/generator"
import makeDialog from "./lib/dialog";

$('.menu-button.right').on('click', function() {
    $(this).addClass('disabled');
})
$('.button.cta').on('click', function() {
    $(this).addClass('disabled');
})

$("textarea").each(function () {
    this.style.height = this.scrollHeight + "px";
    this.style.overflowY = "hidden";
}).on("input", function () {
    this.style.height = "auto";
    this.style.height = this.scrollHeight + "px";
})


document.addEventListener("DOMContentLoaded", () => {
    makeDialog()
})
