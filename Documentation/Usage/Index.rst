..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Look shows the frontend markup of a content element in the page module, inside
an isolated frame. The view helper :html:`<look:backend.contentPreview>` builds
that frame around whatever markup it is given. Where the markup comes from
depends on how the site renders its content elements, and there are two ways:

..  _usage-two-ways:

Two ways to a preview
=====================

**Content Blocks with Fluid Components**, the preferred way
    The backend preview of a Content Block renders the same Fluid component
    as the frontend template, inside the page module request. One component
    is the single source of truth for the website and the page module, no
    TypoScript is involved, and the preview costs one component rendering.
    Take this way wherever Content Blocks and components are in use. See
    :ref:`usage-content-blocks`.

**Classic content types and plugins through TypoScript**
    Content types rendered the classic way (:typoscript:`tt_content` as
    :typoscript:`CASE` object, :typoscript:`FLUIDTEMPLATE`, data processors)
    and plugins (Extbase, :typoscript:`USER` objects) register a preview
    template per type. There, :html:`<look:backend.contentPreview record="{record}" />`
    hands the record to Look, which renders it with the frontend TypoScript
    of its page in a request of its own. This is the way for themes and site
    packages that are not built on Content Blocks, for example the TYPO3 v14
    default theme Camino or fluid_styled_content, and for anything that runs
    PHP of its own during rendering. See :ref:`usage-fluid-templates`.

Why the second way does not render in the page module
-----------------------------------------------------

A Fluid component is a template. Frontend TypoScript is code: data processors
query the database, :typoscript:`USER` objects run arbitrary PHP, Extbase
plugins bootstrap a whole MVC stack that expects a frontend. Running that in
the page module request would mean running it inside an authenticated
backend request, and the frontend has habits that do not belong there:

*   Plugins send headers with PHP's :php:`header()` (redirects, cookies,
    cache control) and would send them for the page module's response.
*   Extbase clears frontend caches on errors and keeps its own configuration
    manager, which would take the backend request for a frontend one from
    then on.
*   TypoScript rendering leaves state behind: :php:`$GLOBALS['TSFE']` in
    TYPO3 13, the page renderer's language, context aspects.
*   An exception, a fatal error or a slow query in one element would break
    or stall the whole page module, not one preview.

Rendering in a separate request keeps all of that in that request. The page
module only emits a description of what to render, the frame fetches the
result with a short-lived token, and a failing element shows an error inside
its frame while the rest of the page module works. The trade-off is one
additional request per visible element, loaded lazily. See
:ref:`security-preview-request` for how the request is protected.

Both ways can be combined in one installation: Content Blocks bring their own
preview template, classic types and plugins get theirs via TSconfig.

..  contents::
    :local:
    :depth: 1

..  _usage-content-blocks:

Previews for Content Blocks with Fluid Components
=================================================

`Content Blocks <https://docs.typo3.org/p/friendsoftypo3/content-blocks/main/en-us/>`__
render the file :file:`templates/backend-preview.html` of a content block in
the page module. With `Fluid Components <https://docs.typo3.org/other/typo3fluid/fluid/main/en-us/Usage/Components.html>`__
the frontend template of the block is a single component call, and the
backend preview makes the same call inside the Look frame.

The component collection of the site package
--------------------------------------------

A component collection makes the components of a directory available under a
Fluid namespace:

..  code-block:: php
    :caption: EXT:my_site/Classes/Components/ComponentCollection.php

    <?php

    declare(strict_types=1);

    namespace Vendor\MySite\Components;

    use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
    use TYPO3Fluid\Fluid\View\TemplatePaths;

    final class ComponentCollection extends AbstractComponentCollection
    {
        public function getTemplatePaths(): TemplatePaths
        {
            $templatePaths = new TemplatePaths();
            $templatePaths->setTemplateRootPaths([
                'EXT:my_site/Resources/Private/Components/',
            ]);

            return $templatePaths;
        }
    }

The component
-------------

The component receives the record and renders the element. It is the only
place where the markup of the element lives:

..  code-block:: html
    :caption: EXT:my_site/Resources/Private/Components/Element/Textmedia/Textmedia.html

    <f:argument name="record" type="TYPO3\CMS\Core\Domain\RecordInterface" />

    <section class="textmedia textmedia--{record.imageorient}">
        <h2 class="textmedia__headline">{record.header}</h2>
        <div class="textmedia__text">
            <f:format.html>{record.bodytext}</f:format.html>
        </div>
        <f:for each="{record.assets}" as="file">
            <f:image image="{file}" class="textmedia__image" maxWidth="1200" />
        </f:for>
    </section>

The frontend template of the block
----------------------------------

The frontend template calls the component with the record Content Blocks
provides as :html:`{data}`:

..  code-block:: html
    :caption: EXT:my_site/ContentBlocks/ContentElements/textmedia/templates/frontend.html

    <html data-namespace-typo3-fluid="true"
          xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
          xmlns:my="http://typo3.org/ns/Vendor/MySite/Components/ComponentCollection">
    <my:element.textmedia record="{data}" />
    </html>

The backend preview of the block
--------------------------------

The backend preview calls the same component, wrapped in the Look frame with
the assets of the frontend build:

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

*   The markup inside the view helper is whatever your frontend produces.
    Here it is the component; it could also be a partial or plain HTML for
    blocks without components. Wrapping the element in the same container
    markup as the frontend page (:html:`<main class="page-content">`) makes
    sure the grid and spacing rules of your CSS apply.

*   :html:`css` and :html:`js` take the assets of your frontend build. They
    are loaded inside the preview frame only, never in the backend itself.

..  figure:: /Images/PreviewSection.png
    :alt: A content element with a coloured section background rendered in the page module
    :class: with-shadow

    Section backgrounds, decorative borders and buttons come from the site's
    own stylesheet.

Why this way is preferred
-------------------------

*   The component is rendered directly. No TypoScript is calculated, no
    content object is resolved, the preview is as cheap as the frontend
    rendering of the element.

*   Frontend and preview cannot drift apart: a change to the component
    changes both.

*   Content Blocks hands the preview the same :php:`Record` object as the
    frontend, including resolved relations such as images.

Hidden relations are shown in the backend by default. If the frontend hides
unpublished images or child records, see :ref:`usage-tips` for how to make
the preview do the same.

..  _usage-fluid-templates:

Previews for classic content types through TypoScript
=====================================================

Content types without Content Blocks are rendered by the :typoscript:`tt_content`
content object of the site's TypoScript: a :typoscript:`CASE` that picks a
:typoscript:`FLUIDTEMPLATE` per type, with template paths and data processors
such as :typoscript:`record-transformation`. For the page module, such types
register a preview template with page TSconfig. Look renders the record inside
that template with the same TypoScript the frontend uses.

Register one template for all types
-----------------------------------

Because the rendering comes from TypoScript, a single preview template serves
every content type. Point each type to the same file:

..  code-block:: typoscript
    :caption: EXT:my_site/Configuration/page.tsconfig

    mod.web_layout.tt_content.preview {
        text = EXT:my_site/Resources/Private/Templates/Preview/Content.html
        textmedia = EXT:my_site/Resources/Private/Templates/Preview/Content.html
        textpic = EXT:my_site/Resources/Private/Templates/Preview/Content.html
        bullets = EXT:my_site/Resources/Private/Templates/Preview/Content.html
        table = EXT:my_site/Resources/Private/Templates/Preview/Content.html
        my_teaser = EXT:my_site/Resources/Private/Templates/Preview/Content.html
    }

In a site set, put the lines into the :file:`page.tsconfig` of the set. When
the set depends on the theme's set, its TSconfig is loaded after the theme's
and replaces the theme's own preview registrations.

The preview template
--------------------

The page module hands the template the record as :html:`{record}`, a
:php:`Record` object of the Record API (TYPO3 13.4 and later). The template
passes it to the view helper as :html:`record` and has no children:

..  code-block:: html
    :caption: EXT:my_site/Resources/Private/Templates/Preview/Content.html

    <html data-namespace-typo3-fluid="true"
          xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
          xmlns:look="http://typo3.org/ns/Flowd/Typo3Look/ViewHelper">
    <look:backend.contentPreview
        record="{record}"
        css="{0: 'EXT:my_site/Resources/Public/Css/main.css'}" />
    </html>

What happens when the preview renders:

#.  In the page module, the view helper emits the frame without content, only
    with a signed description of what to render: the record, its workspace and
    language, the content object and the frame options. No PHP of the site
    runs in the page module request.

#.  When the frame comes into view, Look's script in the page module exchanges
    the description for a token at an authenticated backend route. The token
    is valid for a few seconds and becomes the frame's :html:`src`.

#.  The preview request, a public backend route that accepts only such a
    token, calculates the frontend TypoScript of the record's page (rootline,
    :sql:`sys_template` rows and the sets of the site, the same way Extbase
    does it for backend modules) and renders the :typoscript:`tt_content`
    content object for the record with the core's :php:`ContentObjectRenderer`.
    Templates, partials, layouts and data processors are those of the site's
    TypoScript. A site package that overrides template paths or adds content
    types needs no second configuration for the previews.

Because the element is rendered in its own request, whatever the site's
templates, data processors or plugins do stays there: an exception, a slow
data processor or a plugin that sends headers affects one frame, never the
page module. See :ref:`security-preview-request` for the details of the
token and the request.

While rendering, hidden relations such as unpublished images stay hidden as
on the website, and image processing runs immediately instead of being
deferred as in other backend requests. The preview request has no backend
user; workspace and language come from the token.

Example: a theme's colour scheme
--------------------------------

Themes often expect a class on the body, for example a colour scheme chosen
in the site settings. :html:`<look:site.setting>` reads a setting of the site
the record's page belongs to. The TYPO3 v14 default theme Camino stores its
scheme in :yaml:`camino.colorScheme`:

..  code-block:: html
    :caption: EXT:my_site/Resources/Private/Templates/Preview/Content.html

    <html data-namespace-typo3-fluid="true"
          xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
          xmlns:look="http://typo3.org/ns/Flowd/Typo3Look/ViewHelper">
    <look:backend.contentPreview
        record="{record}"
        bodyClass="{look:site.setting(pageUid: record.pid, name: 'camino.colorScheme')}"
        css="{0: 'EXT:theme_camino/Resources/Public/Css/main.css'}"
        js="{0: 'EXT:theme_camino/Resources/Public/JavaScript/main.js'}" />
    </html>

Switching the scheme in the site settings switches the previews, because the
frame carries the same body class as the website.

Rendering through another content object
----------------------------------------

By default the record is rendered through :typoscript:`tt_content`. The
argument :html:`typoscriptObjectPath` selects another content object, for
example :typoscript:`lib.contentElement` to skip the :typoscript:`CASE` and
render the element's FLUIDTEMPLATE directly:

..  code-block:: html

    <look:backend.contentPreview record="{record}" typoscriptObjectPath="lib.contentElement" css="..." />

..  note::

    The core view helper :html:`f:cObject` also renders content objects in
    the backend, but it does not put the calculated TypoScript on the
    request. TypoScript references such as
    :typoscript:`tt_content.default =< lib.contentElement` are then not
    resolved and the preview stays empty without an error. Use the
    :html:`record` argument instead.

Rendering the element yourself
------------------------------

A preview template can also render the frontend markup directly, for example
with a partial of the site package. Templates written for the frontend's
:typoscript:`record-transformation` data processor work unchanged, because
they receive the same :php:`Record` object:

..  code-block:: html
    :caption: EXT:my_site/Resources/Private/Templates/Preview/Textmedia.html

    <html data-namespace-typo3-fluid="true"
          xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
          xmlns:look="http://typo3.org/ns/Flowd/Typo3Look/ViewHelper">
    <look:backend.contentPreview
        css="{0: 'EXT:my_site/Resources/Public/Css/main.css'}">
        <f:render partial="EXT:my_site/Resources/Private/Partials/Content/Textmedia.html" arguments="{record: record}" />
    </look:backend.contentPreview>
    </html>

Note that the page module gives preview templates no layout or partial root
paths of their own; :html:`f:render partial` needs an absolute :html:`EXT:`
path. This renders in the page module request, like a Content Blocks preview;
hidden relations and deferred image processing are then your concern, see
:ref:`usage-tips`.

See the `TSconfig reference <https://docs.typo3.org/m/typo3/reference-tsconfig/main/en-us/PageTsconfig/Mod.html#mod-web-layout-tt-content-preview>`__
for the details of :typoscript:`mod.web_layout.tt_content.preview`.

..  _usage-choosing:

Choosing between the two ways
=============================

..  list-table::
    :header-rows: 1
    :widths: 30 35 35

    *   -
        - Content Blocks with components
        - Classic types through TypoScript
    *   - Source of the markup
        - The component, called directly
        - The site's TypoScript, rendered by the ContentObjectRenderer
    *   - Where the site's PHP runs
        - In the page module request
        - In a separate preview request
    *   - Preview registration
        - :file:`backend-preview.html` of the block
        - :typoscript:`mod.web_layout.tt_content.preview` per type
    *   - Record in the template
        - :html:`{data}`
        - :html:`{record}`
    *   - Cost per preview
        - One component rendering in the page module
        - One additional request per visible element (token exchange plus rendering), loaded lazily
    *   - Fits
        - Sites built on Content Blocks and Fluid Components
        - Themes and site packages with classic rendering: Camino, fluid_styled_content, own CTypes

Where both are possible, take the component. Where the rendering lives in
TypoScript, take the :html:`record` argument.

..  _usage-viewhelper:

The view helpers
================

look:backend.contentPreview
---------------------------

..  code-block:: html

    <look:backend.contentPreview
        scale="0.6"
        height="400"
        bodyClass="application"
        css="{0: 'EXT:my_site/Resources/Public/Build/main.css'}"
        js="{0: 'EXT:my_site/Resources/Public/Build/main.js'}">
        <!-- frontend markup -->
    </look:backend.contentPreview>

    <look:backend.contentPreview record="{record}" typoscriptObjectPath="tt_content" css="..." />

..  confval-menu::
    :name: viewhelper-arguments
    :display: table
    :type:
    :default:

    ..  confval:: record
        :name: viewhelper-record
        :type: :php:`TYPO3\CMS\Core\Domain\RecordInterface`
        :default: (none)

        Render this record with the frontend TypoScript of its page in a
        separate request instead of the children. Usually the
        :html:`{record}` variable of a preview template registered via
        :typoscript:`mod.web_layout.tt_content.preview`. The view helper must
        have no children then.

    ..  confval:: typoscriptObjectPath
        :name: viewhelper-typoscriptobjectpath
        :type: string
        :default: :code:`tt_content`

        With :html:`record`: dotted path of the content object in the page's
        frontend TypoScript that renders the record.

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

look:site.setting
-----------------

..  code-block:: html

    {look:site.setting(pageUid: record.pid, name: 'theme.colorScheme', default: 'light')}

..  confval-menu::
    :name: site-setting-arguments
    :display: table
    :type:
    :default:

    ..  confval:: pageUid
        :name: site-setting-pageuid
        :type: integer
        :default: (required)

        A page of the site whose settings are read, usually the record's page.

    ..  confval:: name
        :name: site-setting-name
        :type: string
        :default: (required)

        Name of the setting, dotted as in :file:`settings.yaml`.

    ..  confval:: default
        :name: site-setting-default
        :type: string
        :default: (empty)

        Returned when the page belongs to no site or the setting is missing
        or not a scalar value.

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
a view helper, a TypoScript object that does not exist), Look shows a red
callout in place of the preview instead of breaking the page module. For
previews rendered in their own request the callout appears inside the frame,
and even a fatal error or a timeout there leaves the page module intact. In
development context, or with backend debugging enabled, the callout contains
the error message; in production it names only the error code and the
message goes to the TYPO3 log. Fix the template and reload the page module;
there is nothing to clear.

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
    The backend shows hidden relations by default. Previews rendered with the
    :html:`record` argument hide them like the website does. When you render the markup yourself, in a component or a partial,
    reset the visibility aspect of the TYPO3 context while rendering, and set
    the :php:`fileProcessing` aspect to non-deferred so processed images exist
    right away. Otherwise editors see a layout the website never shows, or
    broken images.
