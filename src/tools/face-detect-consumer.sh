#!/usr/bin/env bash

cd "$(dirname "$0")" || exit

/usr/bin/nice -n 10 php face-detect-consumer.php
