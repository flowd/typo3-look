..  include:: /Includes.rst.txt

..  _known-problems:

===============================
Known problems and how to solve
===============================

Most problems come from the isolation of the preview frame. The frame has an
*opaque origin*, which changes how browsers treat a few frontend techniques.
This chapter lists the symptoms and the fixes.

..  contents::
    :local:
    :depth: 1

..  _known-problems-basic-auth:

The previews stay empty on a password-protected site
====================================================

**Symptom:** the website asks for a user name and password before it shows
anything (HTTP Basic Auth, common on staging and preview servers). In the
page module every Look preview is an empty box, about 150 pixels high, with
no styling and no images. The browser console reports 401 errors for the
files inside the frame.

**Cause:** this is intended. The preview frame is deliberately isolated from
the backend, see :ref:`security`. The browser treats it like a page from an
unknown website: it has no login of any kind, neither your TYPO3 session nor
the password you typed into the browser's prompt. Every stylesheet, script and
image the frame requests is therefore refused by the server, and browsers do
not show a password prompt for such requests.

It is the same wall that protects your session. Nothing rendered inside a
preview can reach the backend, and in return the preview cannot borrow the
backend's credentials. Look has no switch to open that wall, because opening
it would give preview content the same access to the backend that you have.

**What you can do:** nothing inside TYPO3, this is a property of the server
setup. The previews work on every installation that is reachable without a
password prompt, which is the normal case for a live site.

In theory the server could be configured to deliver the static files the
previews need (stylesheets, scripts, images, fonts) without asking for the
password, while the pages themselves stay protected. Be aware of what that
means: depending on how it is done, those files become publicly readable
for anyone who knows or guesses their address, including uploaded images
and documents. Whether that is acceptable has to be decided per project.
Look neither recommends nor documents such a setup; if it is done, it is
entirely the responsibility of the people operating the server.

..  _known-problems-external-hosts:

Assets from other hosts do not load
===================================

**Symptom:** web fonts from Google Fonts, a library from a CDN or images from
an external server are missing in the preview, while they work on the
website. The console reports a Content Security Policy violation, not a
CORS error.

**Cause:** the preview document only allows assets from the backend's own
host. Previews rendered in the page module request (srcdoc) inherit that
rule from the backend's Content Security Policy; previews rendered in their
own request (the :html:`record` argument) carry it in their own policy.
Adding a CORS header does not help here, the browser refuses the request
before it is sent.

**Fix:** serve the assets from your own host. For previews rendered in the
page module request you can alternatively extend the backend policy for the
hosts you trust with a :file:`Configuration/ContentSecurityPolicies.php` in
your site package; previews rendered in their own request do not read that
policy:

..  code-block:: php
    :caption: EXT:my_site/Configuration/ContentSecurityPolicies.php

    <?php

    use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
    use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
    use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
    use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
    use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
    use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
    use TYPO3\CMS\Core\Type\Map;

    return Map::fromEntries([
        Scope::backend(),
        new MutationCollection(
            new Mutation(MutationMode::Extend, Directive::FontSrc, new UriValue('https://fonts.gstatic.com')),
            new Mutation(MutationMode::Extend, Directive::StyleSrc, new UriValue('https://fonts.googleapis.com')),
        ),
    ]);

See the `Content Security Policy chapter <https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/ContentSecurityPolicy/Index.html>`__
of the core API reference for the details.

..  _known-problems-fonts:

Fonts or scripts do not load
============================

**Symptom:** the preview uses fallback fonts, or with
:confval:`allowSiteScripts <flag-allow-site-scripts>` the site scripts do not
run. The browser console inside the frame reports a CORS error.

**Cause:** web fonts and JavaScript modules are loaded as cross-origin
requests from the frame. The web server does not send
:code:`Access-Control-Allow-Origin: *` for them. Look's own script is not
affected, it is a classic script.

**Fix:** add the header for the path your frontend build lives in, see
:ref:`installation-webserver`.

..  _known-problems-icons:

SVG icons are missing
=====================

**Symptom:** icons referenced as an external SVG sprite are blank. The console
says "Unsafe attempt to load URL ... from frame with URL about:srcdoc".

**Cause:** :html:`<svg><use href="/icons.svg#play">` may only load files from
the same origin, and the frame has none.

**Fix:** inline the SVG when rendering for the preview. A small view helper
that reads the icon file and returns its markup does the job. Detect the
preview context in your templates, for example with a flag your preview
template sets, and switch between the sprite reference (frontend) and the
inline markup (preview):

..  code-block:: html

    <f:if condition="{isBackendPreview}">
        <f:then>{my:svg.inline(path: 'EXT:my_site/Resources/Public/Icons/{name}.svg', class: 'icon')}</f:then>
        <f:else>
            <svg class="icon"><use href="{f:uri.resource(path: 'EXT:my_site/Resources/Public/Icons/{name}.svg')}#icon"></use></svg>
        </f:else>
    </f:if>

Icons that are inlined in the frontend anyway need no change.

..  _known-problems-three-times:

The preview appears three times
===============================

**Symptom:** with Content Blocks, the whole preview is repeated three times.

**Cause:** Content Blocks renders :file:`backend-preview.html` for the header,
the content and the footer of the element.

**Fix:** use the :html:`Preview` layout with a :html:`Content` section, see
:ref:`usage-content-blocks`.

..  _known-problems-images:

Images are missing, "File ... does not exist"
=============================================

**Symptom:** the preview shows broken images or an error about a processed
file that does not exist.

**Cause:** backend requests defer image processing to a later request. A
frontend template that expects the processed file immediately (for example a
custom picture renderer) does not get it.

**Fix:** set the :php:`fileProcessing` aspect of the TYPO3 context to
non-deferred (:php:`new FileProcessingAspect(false)`) while rendering the
preview, in the same wrapper that handles the visibility aspect.

..  _known-problems-red-callout:

A red callout instead of the preview
====================================

**Symptom:** the page module shows "Preview could not be rendered" with an
error message and a code.

**Cause:** rendering the frontend markup threw an exception.

**Fix:** in development context (or with backend debugging enabled) the
callout names the problem, usually a missing partial, a wrong argument or a
PHP error in a view helper; in production it shows only the error code and
the details go to the TYPO3 log. Correct the template and reload the page
module.

..  _known-problems-cobject-empty:

f:cObject renders nothing in the preview
========================================

**Symptom:** a preview template uses :html:`<f:cObject typoscriptObjectPath="tt_content">`
and the preview shows the frame, but no content and no error.

**Cause:** outside the frontend the core view helper takes the TypoScript
from Extbase's configuration manager but does not put it on the request. The
ContentObjectRenderer resolves references such as
:typoscript:`tt_content.default =< lib.contentElement` only from the request
and silently renders an empty string.

**Fix:** use :html:`<look:backend.contentPreview record="{record}" />`,
see :ref:`usage-fluid-templates`.

..  _known-problems-no-backend-user:

The preview reports an undefined backend user
=============================================

**Symptom:** a preview rendered with the :html:`record` argument shows a
callout mentioning :php:`$GLOBALS['BE_USER']` or a null backend user, while
the same element works in the frontend.

**Cause:** the element is rendered in a separate request without a backend
session, see :ref:`security-preview-request`. Code in the site package, a
data processor or a view helper reads the backend user, which the frontend
never has either.

**Fix:** make the code work without a backend user, as it has to in the
frontend. If the element genuinely depends on the backend user, render its
preview in the page module request instead: leave out :html:`record` and put
the frontend markup into the view helper's children.

..  _known-problems-install-tool:

Nothing works in the standalone Install Tool
============================================

The previews need the nonce of a backend request. The standalone Install Tool
(:file:`/typo3/install.php`) has none and does not render page module
previews, which is expected.
