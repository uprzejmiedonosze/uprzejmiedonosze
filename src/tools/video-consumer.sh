#!/usr/bin/env bash

cd "$(dirname "$0")" || exit

/usr/bin/nice -n 15 php video-consumer.php
