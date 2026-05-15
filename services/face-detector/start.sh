#!/usr/bin/env bash

cd "$(dirname "$0")" || exit

source .venv/bin/activate

/usr/bin/nice -n 10 uvicorn main:app --host 127.0.0.1 --port 2000 --workers 1 --no-use-colors