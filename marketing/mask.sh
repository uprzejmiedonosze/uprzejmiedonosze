#!/bin/bash
set -euo pipefail
IFS=$'\n\t'

MASKA=mask.png
OUT=$(date +%Y-%m-%d).png

composite -geometry +210+230 \( l.png -resize 790x1060 \) ${MASKA} ${OUT}
composite -geometry +819+230 \( r.png -resize 590x1060 \) ${OUT} ${OUT}
