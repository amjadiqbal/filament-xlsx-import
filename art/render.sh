#!/bin/zsh
# Render the SVGs to PNG with Playwright + Chrome. PLAYWRIGHT_CORE defaults to the one inside the global @playwright/mcp.
cd "${0:A:h}"
export PLAYWRIGHT_CORE=${PLAYWRIGHT_CORE:-$(npm root -g)/@playwright/mcp/node_modules/playwright-core}
SHOT="${0:A:h}/svgshot.cjs"
node $SHOT icon.svg icon.png 512 512 1 t
node $SHOT banner-light.svg banner-light.png 1200 300 1
node $SHOT banner-dark.svg banner-dark.png 1200 300 1
node $SHOT social-preview.svg social-preview.png 1280 640 1
for f in *.png; do echo "$f $(magick identify -format '%wx%h' $f)"; done
python3 -I brown_scan.py *.png
