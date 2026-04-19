# ElementsKit Iconpack Generator
A dev tool for generating ElementsKit icon pack files from IcoMoon source. Not required for the plugin to run — only needed when adding or removing icons.

---

## Workflow

### 1. Update SVG and Fonts from IcoMoon

### 2. Push changes to this repository so the latest icons are saved.

```bash
git add .
git commit -m "added elementor icon"
git push
```

### 3. Load the repository into the plugin
From the **ElementsKit Lite plugin root**, run:

```bash
npm run load-iconpack
```

### 4. Generate the icon pack
```bash
npm run iconpack
```

![ElementsKit Iconpack Generator](./iconpack.png)
