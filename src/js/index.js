import $ from "jquery";
// @ts-ignore
window.$ = $;
// @ts-ignore
window.jQuery = $;

import "lazysizes";


import "./lib/send";
import "./lib/sentry";

import "./sites/main";
import "./sites/application";
import "./sites/confirm-application";
import "./sites/faq";
import "./sites/gallery";
import "./sites/my-application";
import "./sites/register";
import "./sites/regulations";
import "./sites/shipment";
import "./sites/start";
import "./sites/statistics";
import "./sites/thank-you";
import "./sites/public-info";
import "./sites/how";
import "./sites/ask-for-status"
import "./sites/menu"

$("a").attr('data-ajax', 'false')
