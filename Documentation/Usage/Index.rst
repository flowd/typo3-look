..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Look provides a single Fluid view helper. Wrap it around the frontend markup
of a content element and the page module shows that markup as a real preview.

..  contents::
    :local:
    :depth: 1

..  _usage-content-blocks:

Previews for Content Blocks
===========================

`Content Blocks <https://docs.typo3.org/p/friendsoftypo3/content-blocks/main/en-us/>`__
render the file :file:`templates/backend-preview.html` of a content block in
the page module. Reuse the frontend rendering there and wrap it in the view
helper:

..  code-block:: html
    :caption: EXT:my_site/ContentBlocks/ContentElements/textmedia/templates/backend-preview.html

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

Three things are worth noting:

*   :html:`<f:layout name="Preview" />` with a :html:`Content` section is the
    layout Content Blocks provides for backend previews. Without it, Content
    Blocks renders the template three times (header, content, footer) and the
    preview appears three times.

*   The markup inside the view helper is whatever your frontend template
    produces. Here it is a Fluid component that receives the record; it could
    just as well be a partial or plain HTML. Wrapping the element in the same
    container markup as the frontend (:html:`<main class="page-content">`)
    makes sure the grid and spacing rules of your CSS apply.

*   :html:`css` and :html:`js` take the assets of your frontend build. They
    are loaded inside the preview frame only, never in the backend itself.

..  figure:: /Images/PreviewSection.png
    :alt: A content element with a coloured section background rendered in the page module
    :class: with-shadow

    Section backgrounds, decorative borders and buttons come from the site's
    own stylesheet.

..  _usage-fluid-templates:

Previews for classic content elements
=====================================

Content element types without Content Blocks can use the view helper in the
Fluid template that TYPO3 renders for the page module preview. Register the
template with page TSconfig:

..  code-block:: typoscript
    :caption: EXT:my_site/Configuration/page.tsconfig

    mod.web_layout.tt_content.preview.textmedia = EXT:my_site/Resources/Private/Templates/Preview/Textmedia.html

The template receives the raw database row as :html:`{record}` and can wrap
its rendering in the view helper the same way:

..  code-block:: html
    :caption: EXT:my_site/Resources/Private/Templates/Preview/Textmedia.html

    <html data-namespace-typo3-fluid="true"
          xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
          xmlns:look="http://typo3.org/ns/Flowd/Typo3Look/ViewHelper">
    <look:backend.contentPreview
        css="{0: 'EXT:my_site/Resources/Public/Css/main.css'}">
        <f:render partial="Content/Textmedia" arguments="{record: record}" />
    </look:backend.contentPreview>
    </html>

See the `TSconfig reference <https://docs.typo3.org/m/typo3/reference-tsconfig/main/en-us/PageTsconfig/Mod.html#mod-web-layout-tt-content-preview>`__
for the details of :typoscript:`mod.web_layout.tt_content.preview`.

..  _usage-viewhelper:

The view helper
===============

..  code-block:: html

    <look:backend.contentPreview
        scale="0.6"
        height="400"
        bodyClass="application"
        css="{0: 'EXT:my_site/Resources/Public/Build/main.css'}"
        js="{0: 'EXT:my_site/Resources/Public/Build/main.js'}">
        <!-- frontend markup -->
    </look:backend.contentPreview>

..  confval-menu::
    :name: viewhelper-arguments
    :display: table
    :type:
    :default:

    ..  confval:: scale
        :name: viewhelper-scale
        :type: float
        :default: extension configuration :confval:`contentPreview.scale <ext-conf-scale>` (0.5)

        Factor the frontend is scaled down with inside the preview. :code:`0.5`
        shows the site at half size, :code:`1` at its natural size. The frame
        is always as wide as the page module column; the scale decides how
        much of the frontend width fits into it.

    ..  confval:: height
        :name: viewhelper-height
        :type: integer
        :default: extension configuration :confval:`contentPreview.height <ext-conf-height>` (0)

        Maximum height of the preview in pixels. Elements that are taller are
        cut off and fade out at the bottom, so editors see that there is more.
        :code:`0` means no limit: the frame grows with its content.

        ..  figure:: /Images/PreviewHeightLimit.png
            :alt: A preview cut off at a fixed height with a fade-out at the bottom
            :class: with-shadow

            A preview with :html:`height="250"`. The fade-out marks that the
            element continues below.

    ..  confval:: bodyClass
        :name: viewhelper-bodyclass
        :type: string
        :default: (empty)

        Class attribute of the :html:`<body>` inside the preview frame. Use it
        when your stylesheet expects a class on the body, for example a theme
        or a scope class.

    ..  confval:: css
        :name: viewhelper-css
        :type: array
        :default: []

        Stylesheets to load inside the frame, as :code:`EXT:` paths or public
        URLs. Usually the CSS bundle of your frontend build. Look's own small
        stylesheet (scaling, fade-out) is always loaded first.

    ..  confval:: js
        :name: viewhelper-js
        :type: array
        :default: []

        JavaScript modules to load inside the frame, as :code:`EXT:` paths or
        public URLs. They are loaded as :html:`<script type="module">` and
        **only when the feature flag** :ref:`allowSiteScripts <feature-flags>`
        **is enabled**. Without it the previews show the static markup with
        CSS only.

..  _usage-assets:

Assets registered by the content
================================

Templates and components inside the preview may register their own assets
with the standard Fluid view helpers :html:`<f:asset.css>` and
:html:`<f:asset.script>`. Look collects those while rendering the content and
puts them into the head of the preview frame. They never reach the backend
page, and they never leak into the preview of another content element.

..  code-block:: html
    :caption: A component that brings its own stylesheet

    <f:asset.css identifier="my-slider" href="EXT:my_site/Resources/Public/Css/slider.css" />
    <f:asset.script identifier="my-slider" src="EXT:my_site/Resources/Public/JavaScript/slider.js" />
    <div class="slider">...</div>

Scripts registered this way follow the same rule as the :html:`js` argument:
they load only when :ref:`allowSiteScripts <feature-flags>` is enabled, and
they get the nonce of the backend request so the preview's Content Security
Policy accepts them.

..  _usage-errors:

When rendering fails
====================

If the frontend markup cannot be rendered (a missing partial, a PHP error in
a view helper), Look shows a red callout in place of the preview instead of
breaking the page module. In development context, or with backend debugging
enabled, the callout contains the error message; in production it names only
the error code and the message goes to the TYPO3 log. Fix the template and
reload the page module; there is nothing to clear.

..  _usage-tips:

Tips for good previews
======================

Render inside the frontend container
    Put the element into the same wrapper markup as the frontend page
    (content container, grid). Otherwise widths, gutters and backgrounds
    look different from the website.

    ..  figure:: /Images/PreviewForm.png
        :alt: A contact form element rendered in the page module
        :class: with-shadow

        A form element with the site's containers and spacing: the preview
        matches the website because it uses the same wrapper markup.

Choose one scale for the whole site
    Set the scale once in the :ref:`extension configuration <configuration>`
    and leave the argument out of the templates. Editors get a consistent
    zoom level across all element types.

Limit the height of long elements
    A list or a slider with dozens of items makes the page module very long.
    Give those element types a :html:`height`, the fade-out tells editors the
    element continues.

Keep hidden things hidden
    The backend shows hidden relations by default. If your frontend hides
    unpublished images or child records, make the preview do the same, for
    example by resetting the visibility aspect of the TYPO3 context while
    rendering the preview. Otherwise editors see a layout the website never
    shows.
