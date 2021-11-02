<?php
/**
 * SEOmatic plugin for Craft CMS 3.x
 *
 * A turnkey SEO implementation for Craft CMS that is comprehensive, powerful,
 * and flexible
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\seomatic\services;

use nystudio107\seomatic\jobs\GenerateSitemap;
use nystudio107\seomatic\models\MetaBundle;
use nystudio107\seomatic\models\NewsSitemapIndexTemplate;
use nystudio107\seomatic\models\NewsSitemapTemplate;
use nystudio107\seomatic\Seomatic;
use nystudio107\seomatic\base\FrontendTemplate;
use nystudio107\seomatic\base\SitemapInterface;
use nystudio107\seomatic\helpers\UrlHelper;
use nystudio107\seomatic\models\FrontendTemplateContainer;
use nystudio107\seomatic\models\SitemapIndexTemplate;
use nystudio107\seomatic\models\SitemapTemplate;
use nystudio107\seomatic\models\SitemapCustomTemplate;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\errors\SiteNotFoundException;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;

use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\caching\TagDependency;

/**
 * @author    nystudio107
 * @package   Seomatic
 * @since     3.0.0
 */
class Sitemaps extends Component implements SitemapInterface
{
    // Constants
    // =========================================================================

    const SEOMATIC_SITEMAPINDEX_CONTAINER = Seomatic::SEOMATIC_HANDLE.SitemapIndexTemplate::TEMPLATE_TYPE;

    const SEOMATIC_NEWS_SITEMAPINDEX_CONTAINER = Seomatic::SEOMATIC_HANDLE.NewsSitemapIndexTemplate::TEMPLATE_TYPE;

    const SEOMATIC_SITEMAP_CONTAINER = Seomatic::SEOMATIC_HANDLE.SitemapTemplate::TEMPLATE_TYPE;

    const SEOMATIC_NEWS_SITEMAP_CONTAINER = Seomatic::SEOMATIC_HANDLE.NewsSitemapTemplate::TEMPLATE_TYPE;

    const SEOMATIC_SITEMAPCUSTOM_CONTAINER = Seomatic::SEOMATIC_HANDLE.SitemapCustomTemplate::TEMPLATE_TYPE;

    const SITEMAP_TYPE_NEWS = 'news';
    const SITEMAP_TYPE_REGULAR = 'regular';

    const SEARCH_ENGINE_SUBMISSION_URLS = [
        'https://www.google.com/ping?sitemap=' => [self::SITEMAP_TYPE_REGULAR, self::SITEMAP_TYPE_NEWS],
        'https://www.bing.com/ping?sitemap=' => [self::SITEMAP_TYPE_REGULAR],
    ];

    // Protected Properties
    // =========================================================================

    /**
     * @var FrontendTemplateContainer
     */
    protected $sitemapTemplateContainer;

    // Public Methods
    // =========================================================================

    /**
     *
     */
    public function init()
    {
        parent::init();
    }

    /**
     * Load in the sitemap frontend template containers
     */
    public function loadSitemapContainers()
    {
        if (Seomatic::$settings->sitemapsEnabled) {
            $this->sitemapTemplateContainer = FrontendTemplateContainer::create();
            // The Sitemap Index
            $sitemapIndexTemplate = SitemapIndexTemplate::create();
            $this->sitemapTemplateContainer->addData($sitemapIndexTemplate, self::SEOMATIC_SITEMAPINDEX_CONTAINER);
            // The News Sitemap Index
            $newsSitemapIndexTemplate = NewsSitemapIndexTemplate::create();
            $this->sitemapTemplateContainer->addData($newsSitemapIndexTemplate, self::SEOMATIC_NEWS_SITEMAPINDEX_CONTAINER);
            // A custom sitemap
            $sitemapCustomTemplate = SitemapCustomTemplate::create();
            $this->sitemapTemplateContainer->addData($sitemapCustomTemplate, self::SEOMATIC_SITEMAPCUSTOM_CONTAINER);
            // A generic sitemap
            $sitemapTemplate = SitemapTemplate::create();
            $this->sitemapTemplateContainer->addData($sitemapTemplate, self::SEOMATIC_SITEMAP_CONTAINER);
            // A news sitemap
            $sitemapTemplate = NewsSitemapTemplate::create();
            $this->sitemapTemplateContainer->addData($sitemapTemplate, self::SEOMATIC_NEWS_SITEMAP_CONTAINER);
            // Handler: UrlManager::EVENT_REGISTER_SITE_URL_RULES
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_SITE_URL_RULES,
                function (RegisterUrlRulesEvent $event) {
                    Craft::debug(
                        'UrlManager::EVENT_REGISTER_SITE_URL_RULES',
                        __METHOD__
                    );
                    // Register our sitemap routes
                    $event->rules = array_merge(
                        $event->rules,
                        $this->sitemapRouteRules()
                    );
                }
            );
        }
    }

    /**
     * @return array
     */
    public function sitemapRouteRules(): array
    {
        $rules = [];
        $groups = Craft::$app->getSites()->getAllGroups();
        $groupId = $groups[0]->id;
        $currentSite = null;
        try {
            $currentSite = Craft::$app->getSites()->getCurrentSite();
        } catch (SiteNotFoundException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
        if ($currentSite) {
            try {
                $groupId = $currentSite->getGroup()->id;
            } catch (InvalidConfigException $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }
        // Add the route to redirect sitemap.xml to the actual sitemap
        $route =
            Seomatic::$plugin->handle
            .'/'
            .'sitemap'
            .'/'
            .'sitemap-index-redirect';
        $rules['sitemap.xml'] = [
            'route' => $route,
        ];
        // Add the route for the sitemap.xsl styles
        $route =
            Seomatic::$plugin->handle
            .'/'
            .'sitemap'
            .'/'
            .'sitemap-styles';
        $rules['sitemap.xsl'] = [
            'route' => $route,
        ];
        // Add the route for the sitemap-empty.xsl styles
        $route =
            Seomatic::$plugin->handle
            .'/'
            .'sitemap'
            .'/'
            .'sitemap-empty-styles';
        $rules['sitemap-empty.xsl'] = [
            'route' => $route,
        ];
        // Add all of the frontend container routes
        foreach ($this->sitemapTemplateContainer->data as $sitemapTemplate) {
            /** @var $sitemapTemplate FrontendTemplate */
            $rules = array_merge(
                $rules,
                $sitemapTemplate->routeRules()
            );
        }

        return $rules;
    }

    /**
     * See if any of the entry types have robots enabled and sitemap urls enabled
     *
     * @param MetaBundle $metaBundle
     * @param string $sitemapType
     *
     * @return bool
     */
    public function anyEntryTypeHasSitemapUrls(MetaBundle $metaBundle, string $sitemapType = self::SITEMAP_TYPE_REGULAR): bool
    {
        $result = false;
        $seoElement = Seomatic::$plugin->seoElements->getSeoElementByMetaBundleType($metaBundle->sourceBundleType);
        if ($seoElement) {
            if (!empty($seoElement::typeMenuFromHandle($metaBundle->sourceHandle))) {
                $section = $seoElement::sourceModelFromHandle($metaBundle->sourceHandle);
                if ($section !== null) {
                    $entryTypes = $section->getEntryTypes();
                    // Fetch each meta bundle for each entry type to see if _any_ of them have sitemap URLs
                    foreach ($entryTypes as $entryType) {
                        $entryTypeBundle = Seomatic::$plugin->metaBundles->getMetaBundleBySourceId(
                            $metaBundle->sourceBundleType,
                            $metaBundle->sourceId,
                            $metaBundle->sourceSiteId,
                            $entryType->id
                        );
                        if ($entryTypeBundle) {
                            $robotsEnabled = true;
                            if (!empty($entryTypeBundle->metaGlobalVars->robots)) {
                                $robotsEnabled = $entryTypeBundle->metaGlobalVars->robots !== 'none' &&
                                    $entryTypeBundle->metaGlobalVars->robots !== 'noindex';
                            }
                            switch ($sitemapType) {
                                case self::SITEMAP_TYPE_NEWS:
                                    if ($entryTypeBundle->metaNewsSitemapVars->newsSitemapEnabled && $robotsEnabled) {
                                        $result = true;
                                    }
                                    break;
                                default:
                                    if ($entryTypeBundle->metaSitemapVars->sitemapUrls && $robotsEnabled) {
                                        $result = true;
                                    }
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param string $template
     * @param array  $params
     *
     * @return string
     */
    public function renderTemplate(string $template, array $params = []): string
    {
        $html = '';
        if (!empty($this->sitemapTemplateContainer->data[$template])) {
            /** @var FrontendTemplate $sitemapTemplate */
            $sitemapTemplate = $this->sitemapTemplateContainer->data[$template];
            $html = $sitemapTemplate->render($params);
        }

        return $html;
    }

    /**
     * Submit the sitemap index to the search engine services
     */
    public function submitSitemapIndex()
    {
        if (Seomatic::$settings->sitemapsEnabled && Seomatic::$environment === 'live' && Seomatic::$settings->submitSitemaps) {
            // Submit the sitemap to each search engine
            $searchEngineUrls = self::SEARCH_ENGINE_SUBMISSION_URLS;
            foreach ($searchEngineUrls as $url => $types) {
                $groups = Craft::$app->getSites()->getAllGroups();
                foreach ($groups as $group) {
                    $groupSiteIds = $group->getSiteIds();
                    if (!empty($groupSiteIds)) {
                        $siteId = $groupSiteIds[0];
                        $sitemapIndexUrls = [];
                        foreach ($types as $sitemapType) {
                            $sitemapIndexUrls[] = $this->sitemapIndexUrlForSiteId($siteId, $sitemapType);
                        }

                        foreach ($sitemapIndexUrls as $sitemapIndexUrl) {
                            if (!empty($sitemapIndexUrl)) {
                                $submissionUrl = $url.urlencode($sitemapIndexUrl);
                                // create new guzzle client
                                $guzzleClient = Craft::createGuzzleClient(['timeout' => 120, 'connect_timeout' => 120]);
                                // Submit the sitemap index to each search engine
                                try {
                                    $guzzleClient->post($submissionUrl);
                                    Craft::info(
                                        'Sitemap index submitted to: '.$submissionUrl,
                                        __METHOD__
                                    );
                                } catch (\Exception $e) {
                                    Craft::error(
                                        'Error submitting sitemap index to: '.$submissionUrl.' - '.$e->getMessage(),
                                        __METHOD__
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Submit the bundle sitemap to the search engine services
     *
     * @param ElementInterface $element
     */
    public function submitSitemapForElement(ElementInterface $element)
    {
        if (Seomatic::$settings->sitemapsEnabled && Seomatic::$environment === 'live' && Seomatic::$settings->submitSitemaps) {
            /** @var Element $element */
            [$sourceId, $sourceBundleType, $sourceHandle, $sourceSiteId, $typeId]
                = Seomatic::$plugin->metaBundles->getMetaSourceFromElement($element);

            // Submit the sitemaps to each search engine
            $searchEngineUrls = self::SEARCH_ENGINE_SUBMISSION_URLS;
            $sitemapUrls = [];

            foreach ($searchEngineUrls as $url => $types) {
                foreach ($types as $sitemapType) {
                    $sitemapUrls[] = $this->sitemapUrlForBundle($sourceBundleType, $sourceHandle, $sourceSiteId, $sitemapType);
                }

                foreach ($sitemapUrls as $sitemapUrl) {
                    if (!empty($sitemapUrl)) {
                        $submissionUrl = $url.urlencode($sitemapUrl);
                        // create new guzzle client
                        $guzzleClient = Craft::createGuzzleClient(['timeout' => 120, 'connect_timeout' => 120]);
                        // Submit the sitemap index to each search engine
                        try {
                            $guzzleClient->post($submissionUrl);
                            Craft::info(
                                'Sitemap index submitted to: '.$submissionUrl,
                                __METHOD__
                            );
                        } catch (\Exception $e) {
                            Craft::error(
                                'Error submitting sitemap index to: '.$submissionUrl.' - '.$e->getMessage(),
                                __METHOD__
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Submit the bundle sitemap to the search engine services
     *
     * @param int $siteId
     */
    public function submitCustomSitemap(int $siteId)
    {
        if (Seomatic::$settings->sitemapsEnabled && Seomatic::$environment === 'live' && Seomatic::$settings->submitSitemaps) {
            // Submit the sitemap to each search engine
            $searchEngineUrls = self::SEARCH_ENGINE_SUBMISSION_URLS;
            foreach ($searchEngineUrls as $url => $types) {
                $sitemapUrl = $this->sitemapCustomUrlForSiteId($siteId);
                if (!empty($sitemapUrl)) {
                    $submissionUrl = $url.urlencode($sitemapUrl);
                    // create new guzzle client
                    $guzzleClient = Craft::createGuzzleClient(['timeout' => 120, 'connect_timeout' => 120]);
                    // Submit the sitemap index to each search engine
                    try {
                        $guzzleClient->post($submissionUrl);
                        Craft::info(
                            'Sitemap Custom submitted to: '.$submissionUrl,
                            __METHOD__
                        );
                    } catch (\Exception $e) {
                        Craft::error(
                            'Error submitting sitemap index to: '.$submissionUrl.' - '.$e->getMessage(),
                            __METHOD__
                        );
                    }
                }
            }
        }
    }

    /**
     * Get the URL to the $siteId's sitemap index with an optional prefix
     *
     * @param int|null $siteId
     * @param string $sitemapType Sitemap type.
     *
     * @return string
     */
    public function sitemapIndexUrlForSiteId(int $siteId = null, string $sitemapType = self::SITEMAP_TYPE_REGULAR): string
    {
        $url = '';
        $sites = Craft::$app->getSites();
        if ($siteId === null) {
            $siteId = $sites->currentSite->id ?? 1;
        }
        $site = $sites->getSiteById($siteId);
        if ($site !== null) {
            try {
                $url = UrlHelper::siteUrl(
                    '/sitemaps'
                    .'-'.$site->groupId
                    .($sitemapType !== self::SITEMAP_TYPE_REGULAR ? '-'.$sitemapType : '')
                    .'-sitemap.xml',
                    null,
                    null,
                    $siteId
                );
            } catch (Exception $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $url;
    }

    /**
     * Return all the sitemap indexes the current group of sites
     *
     * @return string
     */
    public function sitemapIndex(string $sitemapPrefix = ''): string
    {
        $result = '';
        $sites = [];
        // If sitemaps aren't enabled globally, return nothing for the sitemap index
        if (!Seomatic::$settings->sitemapsEnabled) {
            return '';
        }
        if (Seomatic::$settings->siteGroupsSeparate) {
            // Get only the sites that are in the current site's group
            try {
                $siteGroup = Craft::$app->getSites()->getCurrentSite()->getGroup();
            } catch (InvalidConfigException $e) {
                $siteGroup = null;
                Craft::error($e->getMessage(), __METHOD__);
            }
            // If we can't get a group, just use the current site
            if ($siteGroup === null) {
                $sites = [Craft::$app->getSites()->getCurrentSite()];
            } else  {
                $sites = $siteGroup->getSites();
            }
        } else {
            $sites = Craft::$app->getSites()->getAllSites();
        }

        foreach($sites as $site) {
            $result .= 'sitemap: ' . $this->sitemapIndexUrlForSiteId($site->id) . PHP_EOL;
            $result .= 'sitemap: ' . $this->sitemapIndexUrlForSiteId($site->id, self::SITEMAP_TYPE_NEWS) . PHP_EOL;
        }

        return rtrim($result, PHP_EOL);
    }

    /**
     * @param int|null $siteId
     *
     * @return string
     */
    public function sitemapCustomUrlForSiteId(int $siteId = null)
    {
        $url = '';
        $sites = Craft::$app->getSites();
        if ($siteId === null) {
            $siteId = $sites->currentSite->id ?? 1;
        }
        $site = $sites->getSiteById($siteId);
        if ($site) {
            try {
                $url = UrlHelper::siteUrl(
                    '/sitemaps-'
                    .$site->groupId
                    .'-'
                    .SitemapCustomTemplate::CUSTOM_SCOPE
                    .'-'
                    .SitemapCustomTemplate::CUSTOM_HANDLE
                    .'-'
                    .$siteId
                    .'-sitemap.xml',
                    null,
                    null,
                    $siteId
                );
            } catch (Exception $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $url;
    }

    /**
     * @param string   $sourceBundleType
     * @param string   $sourceHandle
     * @param int|null $siteId
     * @param string $sitemapType Sitemap type.
     *
     * @return string
     */
    public function sitemapUrlForBundle(string $sourceBundleType, string $sourceHandle, int $siteId = null, string $sitemapType = self::SITEMAP_TYPE_REGULAR): string
    {
        $url = '';
        $sites = Craft::$app->getSites();
        if ($siteId === null) {
            $siteId = $sites->currentSite->id ?? 1;
        }
        $site = $sites->getSiteById($siteId);
        $metaBundle = Seomatic::$plugin->metaBundles->getMetaBundleBySourceHandle(
            $sourceBundleType,
            $sourceHandle,
            $siteId
        );
        if ($site && $metaBundle) {
            try {
                $url = UrlHelper::siteUrl(
                    '/sitemaps'
                    .'-'.$site->groupId
                    .'-'.$metaBundle->sourceBundleType
                    .'-'.$metaBundle->sourceHandle
                    .'-'.$metaBundle->sourceSiteId
                    .($sitemapType !== self::SITEMAP_TYPE_REGULAR ? '-'.$sitemapType : '')
                    .'-sitemap.xml',
                    null,
                    null,
                    $siteId
                );
            } catch (Exception $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $url;
    }

    /**
     * Invalidate all of the sitemap caches
     */
    public function invalidateCaches()
    {
        $cache = Craft::$app->getCache();
        TagDependency::invalidate($cache, self::GLOBAL_SITEMAP_CACHE_TAG);
        Craft::info(
            'All sitemap caches cleared',
            __METHOD__
        );
    }

    /**
     * Invalidate the sitemap cache passed in $handle
     *
     * @param string $handle
     * @param int    $siteId
     * @param string $type
     */
    public function invalidateSitemapCache(string $handle, int $siteId, string $type)
    {
        $cache = Craft::$app->getCache();
        // If the queue should be run automatically, do it now
        TagDependency::invalidate($cache, SitemapTemplate::SITEMAP_CACHE_TAG.$handle.$siteId);
        TagDependency::invalidate($cache, NewsSitemapTemplate::SITEMAP_CACHE_TAG.$handle.$siteId);
        Craft::info(
            'Sitemap cache cleared: '.$handle,
            __METHOD__
        );
        $sites = Craft::$app->getSites();
        if ($siteId === null) {
            $siteId = $sites->currentSite->id ?? 1;
        }
        $site = $sites->getSiteById($siteId);
        $groupId = $site->groupId;
        $sitemapTemplate = SitemapTemplate::create();
        $sitemapTemplate->render(
            [
                'groupId' => $groupId,
                'type' => $type,
                'handle' => $handle,
                'siteId' => $siteId,
                'throwException' => false,
            ]
        );
        $newSitemapTemplate = NewsSitemapTemplate::create();
        $newSitemapTemplate->render(
            [
                'groupId' => $groupId,
                'type' => $type,
                'handle' => $handle,
                'siteId' => $siteId,
                'throwException' => false,
            ]
        );

    }

    /**
     * Invalidate the sitemap index cache
     */
    public function invalidateSitemapIndexCache()
    {
        $cache = Craft::$app->getCache();
        TagDependency::invalidate($cache, SitemapIndexTemplate::SITEMAP_INDEX_CACHE_TAG);
        Craft::info(
            'Sitemap index cache cleared',
            __METHOD__
        );
    }
}
