# ElementsKit Iconpack Generator
A dev tool for syncing the ElementsKit icon pack from IcoMoon source. Not required for the plugin to run — only needed when adding or removing icons.

---

## Folder Structure

```
iconpack/
├── src/
│   ├── Fonts & Svg/
│   │   ├── fonts/          ← elementskit.svg, .woff, .ttf (source font files)
│   │   └── SVG/            ← individual SVG files (one per icon)
│   ├── Icomoon/            ← drop new IcoMoon .zip exports here
│   ├── Systems File/       ← internal scripts (svg-to-icon-json.php, editor-static.css)
│   ├── selection.json      ← IcoMoon project file
│   └── iconpack.png
├── iconpack-sync.php       ← main sync script
└── README.md
```

---

## Workflow

### Option A — Drop a zip (recommended)

1. Export from [IcoMoon](https://icomoon.io/app/) and drop the `.zip` into `src/Icomoon/`.
2. Run from the plugin root:

```bash
npm run iconpack
```

The script auto-extracts `fonts/` and `SVG/` from the zip, then regenerates all output files.

---



## What the sync script generates

| Output file | Description |
|---|---|
| `modules/elementskit-icon-pack/assets/fonts/elementskit.woff` | Web font copied from source |
| `modules/elementskit-icon-pack/assets/sass/ekiticons.scss` | Font-face + `.icon-*` classes (compile with Grunt) |
| `modules/elementskit-icon-pack/assets/json/icons.json` | SVG map `{ "icon-name": { viewBox, paths } }` |
| `widgets/init/assets/css/editor.css` | Font-face + `.ekit-*` widget panel icon classes |

After syncing, compile the SCSS:

```bash
npm run dev
```

---

## First-time setup

If the `iconpack/` folder doesn't exist yet, clone it first:

```bash
npm run load-iconpack
```

This clones `https://github.com/wpmetcom/iconpack.git` into the `iconpack/` directory.

---


![ElementsKit Iconpack Generator](./src/iconpack.png)
