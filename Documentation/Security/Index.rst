..  include:: /Includes.rst.txt

..  _security:

========
Security
========

A preview shows content that editors wrote, rendered with templates and
scripts of the frontend, inside the backend of the site. Look treats that
content as untrusted and isolates every preview as far as browsers allow.
This chapter explains what is in place and what it means for you.

..  contents::
    :local:
    :depth: 1

..  _security-sandbox:

The preview frame is sandboxed
==============================

Every preview is an :html:`<iframe>` with the :html:`sandbox` attribute set
to :code:`allow-scripts` and nothing else. Browsers then give the frame an
*opaque origin*: the content behaves as if it came from an unknown, foreign
website.

Concretely, content inside a preview

*   **cannot access the backend**: no access to the TYPO3 backend document,
    the editor's session cookie, local storage or session storage;
*   **cannot navigate or open windows**: no links to follow, no popups, no
    redirects of the backend;
*   **cannot submit forms**: a contact form in the preview is just markup;
*   **cannot show dialogs**: no :code:`alert()`, no download prompts;
*   **cannot be clicked**: the frame ignores pointer events, the editor's
    clicks go to the page module controls as usual.

The :html:`referrerpolicy` is :code:`no-referrer`, so requests from the frame
carry no backend URL.

..  _security-csp:

Scripts are limited by a Content Security Policy
================================================

Scripts *may* run inside the frame, otherwise Look could not report the
content height to the page module. Which scripts run is decided by a Content
Security Policy in the preview document:

..  code-block:: text

    script-src 'nonce-<nonce of the backend request>' 'strict-dynamic'

Only script tags that Look itself writes into the document carry that nonce:
its own height script and, with :confval:`allowSiteScripts <flag-allow-site-scripts>`,
the scripts of your frontend build. A :html:`<script>` that arrives inside
the content, for example through an unsafe rich text field, has no nonce and
is refused by the browser. :code:`'strict-dynamic'` lets the trusted scripts
import their own modules.

..  _security-media:

Media are blocked by default
============================

Unless :confval:`allowMedia <flag-allow-media>` is enabled, the same policy
also contains :code:`media-src 'none'; frame-src 'none'`. Video and audio
files are neither downloaded nor played while the page module is open, and
embedded players (YouTube, Vimeo and other iframes) are not loaded either;
videos appear as striped placeholder boxes. Besides bandwidth this avoids a
page module full of playing videos.

Previews rendered in the page module request (srcdoc) also inherit the
Content Security Policy of the TYPO3 backend, whose default only allows assets
from the backend's own host. Previews rendered in their own request (see
:ref:`security-preview-request`) inherit nothing from the backend page, so
their document carries a complete policy of its own, sent as HTTP header and
repeated as :html:`<meta>`: :code:`default-src 'self'`, images and fonts from
the own host or inline as :code:`data:`, stylesheets from the own host or
inline, no connections to other hosts, no plugins, no :html:`<base>`, no form
targets, no media and no embedded frames unless allowed by the feature flag.
Either way, a tracking pixel or a script from a foreign host in an editor's
text does not load in the page module.

..  _security-permissions:

No permissions
==============

Camera, microphone, geolocation, fullscreen, autoplay and the other browser
permissions are not available to the frame. The TYPO3 backend does not grant
them to embedded frames, and the opaque origin of the sandbox denies the rest.

..  _security-implications:

What this means for you
=======================

Enable feature flags deliberately
    The defaults give previews no capabilities beyond CSS. Enabling
    :confval:`allowSiteScripts <flag-allow-site-scripts>` runs your frontend
    build inside the frame. It stays isolated from the backend, but review
    what the build does (tracking, external requests) before enabling it.

Web fonts need a CORS header
    The opaque origin makes web fonts and script modules cross-origin
    requests. Look's own script is a classic script and works without any
    server configuration, but your frontend fonts only load if the server
    answers with :code:`Access-Control-Allow-Origin: *` for their path, see
    :ref:`installation-webserver`. The header is standard practice for public
    static files and exposes nothing the files did not expose before.

Some frontend techniques need adjustments
    External SVG sprites (:html:`<use href="...svg#icon">`) cannot load inside
    an opaque origin, see :ref:`known-problems-icons`. Scripts running in the
    frame (with :confval:`allowSiteScripts <flag-allow-site-scripts>`) have no
    :code:`sessionStorage` or :code:`localStorage` and cannot autoplay media;
    guard such calls in your frontend code as you would for private browsing
    modes.

..  _security-preview-request:

Previews rendered in a separate request
=======================================

Previews with the :html:`record` argument run the site's PHP, the frontend
TypoScript with its templates, data processors and plugins, in a request of
its own instead of the page module request. Whatever that code does, from an
exception to a fatal error, a timeout, a plugin sending headers or leaving
global state behind, ends with that request and never reaches the page module
or the editor's backend session.

How the frame gets its content
------------------------------

#.  The page module emits the frame without a source, only with a signed
    **descriptor**: table and uid of the record, its workspace and language,
    the content object to render, the frame options (stylesheets, scripts,
    body class, scale, height) and the id of the backend user. The signature
    is an HMAC over the site's encryption key. The descriptor grants nothing
    by itself and can be exchanged for tokens for eight hours after the page
    module rendered it.

#.  When the frame comes into view, Look's script in the page module sends
    the descriptor to the **token route**, a regular authenticated AJAX route
    of the backend. The route checks the signature, that the logged in user
    is the one the descriptor was issued for, that the descriptor is younger
    than eight hours, and that the user may still see the record: same
    workspace, read access to the table, the page and the language, and the
    record still on that page. Then it answers with a **token**: the
    descriptor plus an expiry five seconds ahead, signed again. Nothing is
    stored on the server.

#.  The token becomes the frame's :html:`src`, a public backend route. The
    frame has an opaque origin and must work without a session, so the route
    is answered before the backend authentication and never looks at
    cookies; it accepts only the token. Signature and expiry are checked,
    then the element is rendered and the frame document is returned with its
    Content Security Policy as HTTP header. An expired token gets a 410
    response and the page module fetches one fresh token; a bad signature
    gets a 403.

What this means
---------------

*   A token that leaks, through a log or a browser history, is useless
    seconds later and only ever rendered one element for one user's view.
    The descriptor in the page module cannot be turned into a token without
    that user's session, and a descriptor kept from an earlier view stops
    working when the user loses access to the record or eight hours pass.

*   Both signatures use the site's **encryption key**
    (:php:`$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`). Whoever
    knows the key can forge tokens and render any record without a login,
    the same way they could forge everything else TYPO3 signs with it. Keep
    the key out of repositories and logs; rotating it invalidates all
    descriptors and tokens at once, editors just reload the page module.

*   The preview request has **no backend user**. Storages, image processing
    and URL generation behave as in the frontend. Workspace and language are
    taken from the token, so editors in a workspace see their versions.
    TypoScript that expects a backend user, or a data processor reading
    :php:`$GLOBALS['BE_USER']`, fails in the frame with a callout, not in the
    page module.

*   The route is public by necessity, but it renders nothing without a valid
    token, and a token can only be minted by an authenticated backend user
    for a record the page module already showed them.

*   Rendering frontend TypoScript means running the site package's PHP with
    the rights of the web server, as every frontend request does. This is the
    same trust you place in the frontend; nothing in the preview request
    grants it more.

