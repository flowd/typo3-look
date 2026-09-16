..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  _installation-composer:

Install with Composer
=====================

Look is installed like any other TYPO3 extension in a Composer based project:

..  code-block:: bash

    composer require flowd/typo3-look

Afterwards set up the extension so that TYPO3 picks up its configuration:

..  code-block:: bash

    vendor/bin/typo3 extension:setup

That is all for the extension itself. The previews appear as soon as a
content element uses the view helper, see :ref:`usage`.

..  _installation-webserver:

If your frontend uses web fonts
===============================

Look needs no web server configuration. One case is the exception: the preview
frame runs with an *opaque origin*, and browsers fetch web fonts and JavaScript
modules from such a frame as cross-origin requests. They only load if the
server answers with :code:`Access-Control-Allow-Origin: *` for the path they
come from.

This affects web fonts of your frontend build, and the scripts of your build
when you enable :confval:`allowSiteScripts <flag-allow-site-scripts>`. Without
the header the previews still work, they just use fallback fonts. Images,
stylesheets and videos are not affected.

..  code-block:: nginx
    :caption: nginx, for the path your frontend build lives in

    location ~* \.(woff2?|ttf|otf|mjs|js)$ {
        add_header Access-Control-Allow-Origin "*";
    }

The header is safe for public static files, it grants read access to bytes
that are served publicly anyway and browsers never combine :code:`*` with
credentials. Do not add it to dynamic or authenticated paths.

..  _installation-check:

Check the installation
======================

Open a page in the page module that contains a content element with a Look
preview. You should see the element in the frontend design, scaled down. If
you see the plain TYPO3 preview instead, the content element type does not use
the view helper yet. If the frame stays empty or shows a red callout, see
:ref:`known-problems`.
