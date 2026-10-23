# Brand & UI sketches (2026-10-23)

Exploration only — nothing in the app uses these yet.

| Image | What |
| --- | --- |
| `07-logo-primary.png` | **Logo slide** — primary: finger-heart hand with a bee + "BeLikeLocal", honeycomb behind; dark, one-colour, app icon, header |
| `08-logo-inline.png` | **Logo slide** — inline "Be 🫰 Local" (the hand between the words), same variants |
| `06-logo-v2.png` | Logo working sheet (lock-ups, close-up, sizes) |
| `01-logo-directions.png` | Round 1 (superseded): six directions around be → bee / hive and like → heart |
| `02-finger-heart-study.png` | Round 1 (superseded): the 🫰 finger heart with a heart |
| `03-ui-system.png` | What's off in today's UI (annotated screenshot) and a tighter type/control/colour system |
| `04-discover-desktop.png` | Discover redesigned on desktop — **approved** |
| `05-mobile.png` | Mobile: Discover, filters bottom sheet, profile form — **approved** |

Sources are in `src/` (plain HTML + SVG). Re-render with headless Chrome, e.g.

```sh
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --hide-scrollbars \
  --allow-file-access-from-files --force-device-scale-factor=2 --window-size=1600,1320 \
  --screenshot=01-logo-directions.png "file://$PWD/src/logos.html"
```

Main causes of today's oversized UI: Pico scales the root font up to ~131% on
wide screens and gives inputs/selects ~3 rem height; buttons still use Pico's
default blue instead of the brand colour.
