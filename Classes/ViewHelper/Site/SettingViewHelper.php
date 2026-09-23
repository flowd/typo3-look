<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\ViewHelper\Site;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A site setting of the site a page belongs to, for preview templates in the backend where the
 * "site" data processor of the frontend is not available. Typical use: a theme's colour scheme as
 * body class of the preview frame.
 *
 *   <look:backend.contentPreview bodyClass="{look:site.setting(pageUid: record.pid, name: 'theme.colorScheme')}">
 *
 * Returns the default when the page has no site or the setting is not scalar.
 */
final class SettingViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly SiteFinder $siteFinder) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('pageUid', 'int', 'A page of the site', true);
        $this->registerArgument('name', 'string', 'Setting name, dotted as in settings.yaml', true);
        $this->registerArgument('default', 'string', 'Value when the site or the setting is missing', false, '');
    }

    public function render(): string
    {
        $default = is_string($this->arguments['default']) ? $this->arguments['default'] : '';
        $pageUid = $this->arguments['pageUid'];
        $name = $this->arguments['name'];
        if (!is_numeric($pageUid) || !is_string($name) || $name === '') {
            return $default;
        }
        try {
            $site = $this->siteFinder->getSiteByPageId((int)$pageUid);
        } catch (SiteNotFoundException) {
            return $default;
        }
        $value = $site->getSettings()->get($name);

        return is_scalar($value) ? (string)$value : $default;
    }
}
