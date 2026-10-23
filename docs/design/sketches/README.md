# Brand & UI sketches (2026-10-23)

Exploration only — nothing in the app uses these yet.

| Image | What |
| --- | --- |
| `01-logo-directions.png` | Six logo directions around be → bee / hive and like → heart |
| `02-finger-heart-study.png` | The 🫰 finger heart as the "like": variants, lock-ups, icon sizes, in-app use |
| `03-ui-system.png` | What's off in today's UI (annotated screenshot) and a tighter type/control/colour system |
| `04-discover-desktop.png` | Discover redesigned on desktop |
| `05-mobile.png` | Mobile: Discover, filters bottom sheet, profile form |

Sources are in `src/` (plain HTML + SVG). Re-render with headless Chrome, e.g.

```sh
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --hide-scrollbars \
  --allow-file-access-from-files --force-device-scale-factor=2 --window-size=1600,1320 \
  --screenshot=01-logo-directions.png "file://$PWD/src/logos.html"
```

Main causes of today's oversized UI: Pico scales the root font up to ~131% on
wide screens and gives inputs/selects ~3 rem height; buttons still use Pico's
default blue instead of the brand colour.
