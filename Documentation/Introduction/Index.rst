..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _what-it-does:

What does it do?
================

The TYPO3 page module normally shows a content element as a plain summary: a
headline, the first lines of text, maybe a thumbnail. Editors have to open the
frontend to see what they actually built.

Look changes that. It renders every content element with the **real frontend
templates, stylesheets and images of your website** and shows the result
directly in the page module, scaled down to fit. What you see in the backend
is what visitors get on the website.

..  figure:: /Images/PageModule.png
    :alt: The TYPO3 page module showing content elements rendered with the real frontend design
    :class: with-shadow

    Content elements in the page module, rendered with the frontend design of
    the site.

Each preview lives in its own **isolated frame**. It looks like the frontend
but cannot interact with the backend: no clicks, no scripts of the page
content, no access to the editor's session. The frame adapts its height to
the content automatically, so short and tall elements both look natural.

..  figure:: /Images/PreviewTextMedia.png
    :alt: A text and media element with headline, text and an image in the page module
    :class: with-shadow

    A text and media element as editors see it: the frontend layout with
    headline, copy and image.

..  _for-whom:

Who is it for?
==============

*   **Editors** see the effect of every setting immediately: a different
    header layout, a coloured section, an image position. No more switching
    between backend and frontend. With the edit overlay enabled, a click on
    the preview opens the element for editing.

*   **Integrators** reuse the frontend templates they already have. A preview
    is one Fluid tag around the existing rendering, there is no second
    template to keep in sync.

*   **Administrators** get previews that are safe by default. Scripts and
    media of the website are switched off inside the preview until they are
    explicitly allowed, see :ref:`security`.

..  _how-it-works:

How does it work?
=================

Look's central view helper is :html:`<look:backend.contentPreview>`. You
wrap it around the frontend markup of a content element. The view helper

#.  renders the markup you pass it,
#.  puts it into a complete HTML document together with the stylesheets and
    scripts of your site,
#.  and shows that document in a sandboxed :html:`<iframe>` in the page module.

Where the markup comes from depends on the site. With Content Blocks and
Fluid Components, the backend preview of a block calls the same component as
the frontend, the preferred way. Classic content types rendered through
TypoScript hand the record to the view helper, which renders it with the
frontend TypoScript of its page in a separate request, so the site's PHP
never runs inside the page module. Both are described in :ref:`usage`.

The frame reports its content height to the backend, so the page module
always shows the whole element (or a fixed height with a fade-out, if you
prefer). Everything else, from the design to the icons, comes from your own
frontend build.

..  _compatibility:

Compatibility
=============

..  t3-field-list-table::
    :header-rows: 1

    -   :Version: Look version
        :TYPO3: TYPO3 version
        :PHP: PHP version

    -   :Version: main
        :TYPO3: 13.4 LTS, 14.3+
        :PHP: 8.2 or later
