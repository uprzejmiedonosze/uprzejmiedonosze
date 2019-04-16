#!/bin/bash
set -euo pipefail
IFS=$'\n\t'

MASKA=mask.png
OUT=$(date +%Y-%m-%d).png

composite -geometry +162+230 \( l.jpg -resize 530x1060 \) ${MASKA} ${OUT}
composite -geometry +814+230 \( p.jpg -resize 530x1060 \) ${OUT} ${OUT}
