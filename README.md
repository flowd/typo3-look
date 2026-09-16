# Look - real frontend previews in the TYPO3 page module

[![CI](https://github.com/flowd/typo3-look/actions/workflows/ci.yml/badge.svg)](https://github.com/flowd/typo3-look/actions/workflows/ci.yml)
[![TYPO3 13 / 14](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014.3-orange)](https://get.typo3.org/)
[![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

"Look" what you'll see on your website. Look renders content elements in the TYPO3 page module
with the **real frontend templates, CSS and images of your site**, inside an isolated preview
frame. Editors see what visitors get, without switching to the frontend.

![Content elements in the page module, rendered with the frontend design](Documentation/Images/PageModule.png)

## What it does

- **Real rendering.** One Fluid view helper wraps the frontend markup of a content element and
  shows it scaled down in the page module. No second set of preview templates to maintain.
- **Isolated by default.** Every preview is a sandboxed `<iframe>` with an opaque origin: no
  access to the backend, its session or storage, no forms, no navigation, no clicks. A Content
  Security Policy limits scripts to the ones Look emits; media files and embedded players are
  blocked until allowed.
- **Fits the page module.** The frame adapts its height to the content, or you cap it and get a
  fade-out. Scale and height are configured once for the whole site.
- **Assets stay in the frame.** Stylesheets and scripts registered with `f:asset.css` /
  `f:asset.script` inside the preview land in the frame, not in the backend page.
- **Click to edit.** Optionally, hovering a preview shows the familiar edit icon and a click opens
  the element for editing (contextual edit panel on TYPO3 14.3+, classic form before), only for
  users who may edit it.

![Hovering a preview shows the edit overlay](Documentation/Images/PreviewEditOverlay.png)

## Requirements

- TYPO3 13.4 LTS or 14.3+
- PHP 8.2 to 8.5

No web server configuration is needed. Two things are worth knowing about the sandboxed frame: web
fonts of your frontend build need `Access-Control-Allow-Origin` on their path, and the preview
inherits the backend Content Security Policy, which blocks assets from other hosts unless you
extend it. Both are covered in the documentation.

## Installation

```bash
composer require flowd/typo3-look
vendor/bin/typo3 extension:setup
```

## Quick start

Wrap the frontend rendering of a content element in the view helper. With Content Blocks this is
the `backend-preview.html` of the block:

```html
<html data-namespace-typo3-fluid="true"
      xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:look="http://typo3.org/ns/Flowd/Typo3Look/ViewHelper"
      xmlns:my="http://typo3.org/ns/Vendor/MySite/Components/ComponentCollection">
<f:layout name="Preview" />
<f:section name="Content">
    <look:backend.contentPreview
        bodyClass="application"
        css="{0: 'EXT:my_site/Resources/Public/Build/main.css'}"
        js="{0: 'EXT:my_site/Resources/Public/Build/main.js'}">
        <main class="page-content">
            <my:element.textmedia record="{data}" />
        </main>
    </look:backend.contentPreview>
</f:section>
</html>
```

Classic content elements use the same view helper in the template registered via
`mod.web_layout.tt_content.preview.<CType>`.

| Argument    | Description                                                                  |
|-------------|------------------------------------------------------------------------------|
| `css`       | Stylesheets to load inside the frame (`EXT:` paths or URLs)                  |
| `js`        | JavaScript modules to load inside the frame (needs `allowSiteScripts`)       |
| `bodyClass` | Class attribute of the `<body>` inside the frame                             |
| `scale`     | Zoom factor, default from the extension configuration (0.5)                  |
| `height`    | Maximum height in pixels with fade-out, default from the extension configuration (0 = unlimited) |

## Configuration

**Extension configuration** (Admin Tools > Settings > Extension Configuration > look):
`contentPreview.scale` and `contentPreview.height` set the defaults for all previews.

**Feature flags** in `config/system/settings.php` or `additional.php`, all off by default. Turning
a flag on always grants the preview an additional capability:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['look.contentPreview.allowSiteScripts'] = true; // run the site's JavaScript inside the frame
$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['look.contentPreview.allowMedia'] = true;       // load video, audio and embedded players
$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['look.contentPreview.editOverlay'] = true;      // hover overlay that opens the edit form
```

## Documentation

The full manual (installation, usage, configuration, security model, known problems and their
fixes) lives in [`Documentation/`](Documentation/Index.rst) and is rendered at
https://docs.typo3.org/p/flowd/typo3-look/main/en-us/.

## Development

```bash
composer install     # creates .Build/ with the tool chain
composer check       # PHP-CS-Fixer, PHPStan (level max), Rector, unit tests
composer fix         # apply Rector and PHP-CS-Fixer
```

Releases: publish a GitHub release whose tag equals the version in `ext_emconf.php`; the
`publish` workflow uploads the extension to the TER (secret `TYPO3_API_TOKEN`).

## License

GPL-2.0-or-later. Made by [Flowd GmbH](https://www.flowd.de).
