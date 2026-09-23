..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Look works without any configuration. Two kinds of settings exist: defaults
for the appearance of the previews (extension configuration) and switches
that grant the previews additional capabilities (feature flags).

..  contents::
    :local:
    :depth: 1

..  _extension-configuration:

Extension configuration
=======================

Open :guilabel:`Admin Tools > Settings > Extension Configuration` and choose
:guilabel:`look`. The values are used whenever the view helper is called
without the corresponding argument.

..  figure:: /Images/ExtensionConfiguration.png
    :alt: The extension configuration of look with the fields default scale and default maximum height
    :class: with-shadow

    Default scale and default maximum height in the extension configuration.

..  confval-menu::
    :name: ext-conf
    :display: table
    :type:
    :default:

    ..  confval:: contentPreview.scale
        :name: ext-conf-scale
        :type: string (decimal number)
        :default: 0.5

        Factor all previews are scaled down with unless a template sets its
        own :confval:`scale <viewhelper-scale>`. :code:`0.5` shows the frontend
        at half size, :code:`0.6` at 60 percent, :code:`1` at full size.

        A good starting point is the width of your page module divided by the
        width of your frontend layout. With a 1200 pixel wide frontend and a
        page module column of about 700 pixels, :code:`0.6` shows the whole
        width of the site.

    ..  confval:: contentPreview.height
        :name: ext-conf-height
        :type: positive integer
        :default: 0

        Maximum height of all previews in pixels unless a template sets its
        own :confval:`height <viewhelper-height>`. Taller elements are cut off
        with a fade-out. :code:`0` means no limit.

The same values can be set in :file:`config/system/settings.php` or
:file:`additional.php`, for example in a deployment:

..  code-block:: php
    :caption: config/system/settings.php (excerpt)

    'EXTENSIONS' => [
        'look' => [
            'contentPreview' => [
                'scale' => '0.6',
                'height' => '0',
            ],
        ],
    ],

..  _feature-flags:

Feature flags
=============

The preview frame is locked down by default (see :ref:`security`). Two
feature flags open it up step by step, a third adds an editing shortcut. All
are **off** unless you enable them, and enabling a flag always means giving
the preview an additional capability.

Set them in :file:`config/system/settings.php` or :file:`additional.php`:

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['look.contentPreview.allowSiteScripts'] = true;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['look.contentPreview.allowMedia'] = true;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['look.contentPreview.editOverlay'] = true;

Sandbox capabilities
--------------------

..  confval-menu::
    :name: feature-flags-list
    :display: table
    :default:

    ..  confval:: look.contentPreview.allowSiteScripts
        :name: flag-allow-site-scripts
        :default: false

        Loads the JavaScript of your site inside the previews: the modules of
        the :confval:`js <viewhelper-js>` argument and scripts registered with
        :html:`<f:asset.script>`.

        **Off:** the previews show the static markup with CSS only. Sliders
        show all slides, masonry grids fall back to their CSS layout, tabs
        show their first pane. Look's own small script for the frame height
        still runs.

        **On:** the site scripts run inside the frame, so interactive elements
        look like on the website. The scripts run in the isolated frame and
        cannot reach the backend, but they do execute, so only enable the
        flag for a frontend build you trust.

    ..  confval:: look.contentPreview.allowMedia
        :name: flag-allow-media
        :default: false

        Loads video and audio files and embedded players inside the previews.

        **Off:** the preview document carries a Content Security Policy that
        blocks media files and embedded frames (YouTube, Vimeo, ...), so opening
        the page module does not download videos. Videos show as striped
        placeholder boxes in the size the layout reserves for them (Firefox
        and Safari keep their play button on top of the box).

        ..  figure:: /Images/PreviewMediaBlocked.png
            :alt: A text and media element whose video shows as a striped placeholder box in the preview
            :class: with-shadow

            A content element with a video while media are blocked: the layout
            is complete, the video area is a placeholder.

        **On:** videos, audio and embedded players load inside the previews.
        Previews rendered in their own request allow media and player frames
        from any :code:`https:` host, because that is where players live;
        previews rendered in the page module request (srcdoc) stay within the
        backend's Content Security Policy, which you can extend for the hosts
        you need. Autoplay still does not happen inside the page module, the
        sandbox does not grant that permission to embedded frames.

..  _feature-flag-edit-overlay:

Edit overlay
------------

..  confval:: look.contentPreview.editOverlay
    :name: flag-edit-overlay
    :default: false

    Turns every preview into a shortcut for editing. Editors who hover a
    preview see a pencil icon in a circle, the same icon as in the element
    header, and a click behaves exactly like that header button: on TYPO3
    14.3 and later the contextual edit panel slides in from the side, on
    older versions, which do not know the panel, the classic edit form opens. Editors who switched the
    contextual panel off in their user settings get the classic form here
    as well.

    ..  figure:: /Images/PreviewEditOverlay.png
        :alt: A preview with the hover overlay showing a pencil icon in a circle
        :class: with-shadow

        Hovering a preview with the edit overlay enabled.

    The overlay only appears for users who are allowed to edit the element.
    Look applies the same rules as the edit button in the element header:
    administrators always see it; everybody else needs modify access to the
    table, content edit permission on the page, a page that is not locked for
    editing, and edit access to the record (table, language, record lock).
    Users without these rights get the plain preview, so nobody is offered an
    action they cannot perform.

    ..  note::
        The record check relies on :php:`BackendUserAuthentication::checkRecordEditAccess()`
        (TYPO3 14) respectively :php:`recordEditAccessInternals()` (TYPO3 13).
        Both are marked ``@internal`` by the TYPO3 core; they are the only
        way to apply exactly the rules the header button applies. Look's unit
        tests pin their behaviour, so a change in a core release is noticed.

    Look finds the record on its own from the template variables: the
    :html:`data` object of a Content Blocks preview or the :html:`record`
    array of a classic preview template. Nothing has to be passed to the view
    helper.

..  _configuration-caches:

After changing the configuration
================================

Extension configuration and feature flags are read on every request, the
previews themselves are rendered on every load of the page module. Reload
the page module after a change; no cache needs to be flushed.
