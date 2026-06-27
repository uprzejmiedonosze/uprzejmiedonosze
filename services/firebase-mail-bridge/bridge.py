#!/usr/bin/env python3
"""Dev-only bridge: Firebase Auth Emulator -> SMTP (Mailpit).

The Auth Emulator never sends real email; it only exposes generated OOB links
(email sign-in, password reset, verification) at its REST endpoint. This poller
picks up each new link and relays it as a real email into Mailpit, so dev gets a
prod-like flow: enter email -> message lands in http://localhost:8025 -> click
the link -> FirebaseUI completes sign-in.

Stdlib only (urllib + smtplib) so it runs on a bare python:*-alpine image.
"""
import email.utils
import html
import json
import os
import smtplib
import sys
import time
import urllib.error
import urllib.request
from email.message import EmailMessage

EMULATOR_HOST = os.environ.get("EMULATOR_HOST", "firebase-emulator:9099")
FIREBASE_PROJECT = os.environ.get("FIREBASE_PROJECT", "demo-project")
SMTP_HOST = os.environ.get("BRIDGE_SMTP_HOST", "mailpit")
SMTP_PORT = int(os.environ.get("BRIDGE_SMTP_PORT", "1025"))
MAIL_FROM = os.environ.get("BRIDGE_MAIL_FROM", "no-reply@uprzejmiedonosze.net")
POLL_INTERVAL = float(os.environ.get("BRIDGE_POLL_INTERVAL", "2"))

OOB_URL = f"http://{EMULATOR_HOST}/emulator/v1/projects/{FIREBASE_PROJECT}/oobCodes"

# requestType -> (subject, lead line). Falls back to a generic entry.
TEMPLATES = {
    "EMAIL_SIGNIN": ("Zaloguj się do uprzejmiedonosze.net", "Kliknij, aby się zalogować:"),
    "PASSWORD_RESET": ("Reset hasła — uprzejmiedonosze.net", "Kliknij, aby zresetować hasło:"),
    "VERIFY_EMAIL": ("Potwierdź adres e-mail", "Kliknij, aby potwierdzić adres e-mail:"),
}


def log(*args):
    print("[fb-mail-bridge]", *args, file=sys.stderr, flush=True)


def fetch_oob_codes():
    with urllib.request.urlopen(OOB_URL, timeout=5) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    return data.get("oobCodes", [])


def send_email(entry):
    to_addr = entry.get("email")
    link = entry.get("oobLink")
    if not to_addr or not link:
        log("skipping entry without email/oobLink:", entry)
        return

    subject, lead = TEMPLATES.get(
        entry.get("requestType", ""),
        ("Wiadomość z uprzejmiedonosze.net (dev)", "Kliknij w link:"),
    )

    msg = EmailMessage()
    msg["From"] = MAIL_FROM
    msg["To"] = to_addr
    msg["Subject"] = subject
    msg["Date"] = email.utils.formatdate(localtime=True)
    msg["Message-ID"] = email.utils.make_msgid(domain="uprzejmiedonosze.net")
    msg["X-UD-OOB-Request-Type"] = entry.get("requestType", "UNKNOWN")

    msg.set_content(f"{lead}\n\n{link}\n\n--\nWysłane przez firebase-mail-bridge (dev).")
    safe_link = html.escape(link, quote=True)
    msg.add_alternative(
        f"""<html><body style="font-family:sans-serif">
  <p>{html.escape(lead)}</p>
  <p><a href="{safe_link}" style="display:inline-block;padding:10px 18px;
     background:#1565c0;color:#fff;text-decoration:none;border-radius:4px">
     {html.escape(subject)}</a></p>
  <p style="font-size:12px;color:#888;word-break:break-all">{safe_link}</p>
  <hr><p style="font-size:11px;color:#aaa">Wysłane przez firebase-mail-bridge (dev).</p>
</body></html>""",
        subtype="html",
    )

    with smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=10) as smtp:
        smtp.send_message(msg)
    log(f"relayed {entry.get('requestType')} for {to_addr} -> {SMTP_HOST}:{SMTP_PORT}")


def main():
    log(f"polling {OOB_URL} every {POLL_INTERVAL}s, relaying to {SMTP_HOST}:{SMTP_PORT}")
    seen = set()
    while True:
        try:
            for entry in fetch_oob_codes():
                code = entry.get("oobCode")
                if not code or code in seen:
                    continue
                seen.add(code)
                try:
                    send_email(entry)
                except Exception as err:  # noqa: BLE001 - keep the poller alive
                    log("failed to relay:", err)
                    seen.discard(code)  # retry next tick
        except urllib.error.URLError as err:
            log("emulator not reachable yet:", err)
        except Exception as err:  # noqa: BLE001
            log("poll error:", err)
        time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    main()
