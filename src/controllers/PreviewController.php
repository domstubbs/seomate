<?php
/**
 * SEOMate plugin for Craft CMS 3.x
 *
 * -
 *
 * @link      https://www.vaersaagod.no/
 * @copyright Copyright (c) 2018 Værsågod
 */

namespace vaersaagod\seomate\controllers;

use Craft;

use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\models\Section;
use craft\web\Controller;
use craft\web\Response;
use craft\web\View;

use DateTime;

use vaersaagod\seomate\SEOMate;
use yii\web\BadRequestHttpException;


/**
 * Sitemap Controller
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Værsågod
 * @package   SEOMate
 * @since     1.0.0
 */
class PreviewController extends Controller
{

    /**
     * Previews an Entry or a Category
     *
     * @return Response
     * @throws NotFoundHttpException if the requested entry version cannot be found
     */
    public function actionIndex(): Response
    {

        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        // What kind of element is it?
        if ($entryId = Craft::$app->getRequest()->getParam('entryId') !== null) {

            // Are we previewing a version?
            $versionId = Craft::$app->getRequest()->getBodyParam('versionId');
            if ($versionId) {
                $entry = Craft::$app->getEntryRevisions()->getVersionById($versionId);
                if (!$entry) {
                    throw new NotFoundHttpException('Entry version not found');
                }
                $this->_enforceEditEntryPermissions($entry);
            } else {
                $entry = $this->_getEntryModel();
                $this->_enforceEditEntryPermissions($entry);
                // Set the language to the user's preferred language so DateFormatter returns the right format
                Craft::$app->updateTargetLanguage(true);
                $this->_populateEntryModel($entry);
            }

            return $this->_showEntry($entry);

        } else if ($categoryId = Craft::$app->getRequest()->getParam('categoryId') !== null) {

            $category = $this->_getCategoryModel();
            $this->_enforceEditCategoryPermissions($category);
            $this->_populateCategoryModel($category);

            return $this->_showCategory($category);

        }

        throw new BadRequestHttpException();
    }

    /**
     * Enforces all Edit Category permissions.
     *
     * @param Category $category
     */
    private function _enforceEditCategoryPermissions(Category $category)
    {
        $isCraft31 = \version_compare(Craft::$app->getVersion(), '3.1', '>=');
        if (Craft::$app->getIsMultiSite()) {
            // Make sure they have access to this site
            $this->requirePermission('editSite:' . ($isCraft31 ? $category->getSite()->uid : $category->getSite()->id));
        }
        // Make sure the user is allowed to edit categories in this group
        $this->requirePermission('editCategories:' . ($isCraft31 ? $category->getGroup()->uid : $category->getGroup()->id));
    }

    /**
     * Fetches or creates a Category.
     *
     * @return Category
     * @throws BadRequestHttpException if the requested category group doesn't exist
     * @throws NotFoundHttpException if the requested category cannot be found
     */
    private function _getCategoryModel(): Category
    {
        $request = Craft::$app->getRequest();
        $categoryId = $request->getBodyParam('categoryId');
        $siteId = $request->getBodyParam('siteId');
        if ($categoryId) {
            $category = Craft::$app->getCategories()->getCategoryById($categoryId, $siteId);
            if (!$category) {
                throw new NotFoundHttpException('Category not found');
            }
        } else {
            $groupId = $request->getRequiredBodyParam('groupId');
            if (($group = Craft::$app->getCategories()->getGroupById($groupId)) === null) {
                throw new BadRequestHttpException('Invalid category group ID: ' . $groupId);
            }
            $category = new Category();
            $category->groupId = $group->id;
            $category->fieldLayoutId = $group->fieldLayoutId;
            if ($siteId) {
                $category->siteId = $siteId;
            }
        }
        return $category;
    }

    /**
     * Populates an Category with post data.
     *
     * @param Category $category
     */
    private function _populateCategoryModel(Category $category)
    {
        // Set the category attributes, defaulting to the existing values for whatever is missing from the post data
        $request = Craft::$app->getRequest();
        $category->slug = $request->getBodyParam('slug', $category->slug);
        $category->enabled = (bool)$request->getBodyParam('enabled', $category->enabled);
        $category->title = $request->getBodyParam('title', $category->title);
        $fieldsLocation = $request->getParam('fieldsLocation', 'fields');
        $category->setFieldValuesFromRequest($fieldsLocation);
        // Parent
        if (($parentId = $request->getBodyParam('parentId')) !== null) {
            if (is_array($parentId)) {
                $parentId = reset($parentId) ?: '';
            }
            $category->newParentId = $parentId ?: '';
        }
    }

    /**
     * Displays a category.
     *
     * @param Category $category
     * @return Response
     * @throws ServerErrorHttpException if the category doesn't have a URL for the site it's configured with, or if the category's site ID is invalid
     */
    private function _showCategory(Category $category): Response
    {
        $categoryGroupSiteSettings = $category->getGroup()->getSiteSettings();
        if (!isset($categoryGroupSiteSettings[$category->siteId]) || !$categoryGroupSiteSettings[$category->siteId]->hasUrls) {
            throw new ServerErrorHttpException('The category ' . $category->id . ' doesn’t have a URL for the site ' . $category->siteId . '.');
        }
        $site = Craft::$app->getSites()->getSiteById($category->siteId);
        if (!$site) {
            throw new ServerErrorHttpException('Invalid site ID: ' . $category->siteId);
        }
        Craft::$app->language = $site->language;
        Craft::$app->set('locale', Craft::$app->getI18n()->getLocaleById($site->language));
        // Have this category override any freshly queried categories with the same ID/site
        Craft::$app->getElements()->setPlaceholderElement($category);

        // Get meta
        $view = $this->getView();
        $view->getTwig()->disableStrictVariables();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        $meta = SEOMate::$plugin->meta->getContextMeta(\array_merge($view->getTwig()->getGlobals(), [
            'seomate' => [
                'element' => $category,
                'config' => [
                    'cacheEnabled' => false,
                ],
            ],
        ]));

        // Render previews
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        return $this->renderTemplate('seomate/preview', [
            'category' => $category,
            'meta' => $meta,
        ]);
    }

    /**
     * Enforces all Edit Entry permissions.
     *
     * @param Entry $entry
     * @param bool $duplicate
     */
    private function _enforceEditEntryPermissions(Entry $entry, bool $duplicate = false)
    {
        $isCraft31 = \version_compare(Craft::$app->getVersion(), '3.1', '>=');
        $userSession = Craft::$app->getUser();
        $permissionSuffix = ':' . ($isCraft31 ? $entry->getSection()->uid : $entry->getSection()->id);
        if (Craft::$app->getIsMultiSite()) {
            // Make sure they have access to this site
            $this->requirePermission('editSite:' . ($isCraft31 ? $entry->getSite()->uid : $entry->getSite()->id));
        }
        // Make sure the user is allowed to edit entries in this section
        $this->requirePermission('editEntries' . $permissionSuffix);
        // Is it a new entry?
        if (!$entry->id || $duplicate) {
            // Make sure they have permission to create new entries in this section
            $this->requirePermission('createEntries' . $permissionSuffix);
        } else {
            switch (get_class($entry)) {
                case Entry::class:
                    // If it's another user's entry (and it's not a Single), make sure they have permission to edit those
                    if (
                        $entry->authorId != $userSession->getIdentity()->id &&
                        $entry->getSection()->type !== Section::TYPE_SINGLE
                    ) {
                        $this->requirePermission('editPeerEntries' . $permissionSuffix);
                    }
                    break;
                case EntryDraft::class:
                    // If it's another user's draft, make sure they have permission to edit those
                    /** @var EntryDraft $entry */
                    if (!$entry->creatorId || $entry->creatorId != $userSession->getIdentity()->id) {
                        $this->requirePermission('editPeerEntryDrafts' . $permissionSuffix);
                    }
                    break;
            }
        }
    }

    /**
     * Fetches or creates an Entry.
     *
     * @return Entry
     * @throws NotFoundHttpException if the requested entry cannot be found
     */
    private function _getEntryModel(): Entry
    {
        $request = Craft::$app->getRequest();
        $entryId = $request->getBodyParam('entryId');
        $siteId = $request->getBodyParam('siteId');
        if ($entryId) {
            $entry = Craft::$app->getEntries()->getEntryById($entryId, $siteId);
            if (!$entry) {
                throw new NotFoundHttpException('Entry not found');
            }
        } else {
            $entry = new Entry();
            $entry->sectionId = $request->getRequiredBodyParam('sectionId');
            if ($siteId) {
                $entry->siteId = $siteId;
            }
        }
        return $entry;
    }

    /**
     * Populates an Entry with post data.
     *
     * @param Entry $entry
     */
    private function _populateEntryModel(Entry $entry)
    {
        $request = Craft::$app->getRequest();
        // Set the entry attributes, defaulting to the existing values for whatever is missing from the post data
        $entry->typeId = $request->getBodyParam('typeId', $entry->typeId);
        $entry->slug = $request->getBodyParam('slug', $entry->slug);
        if (($postDate = $request->getBodyParam('postDate')) !== null) {
            $entry->postDate = DateTimeHelper::toDateTime($postDate) ?: null;
        }
        if (($expiryDate = $request->getBodyParam('expiryDate')) !== null) {
            $entry->expiryDate = DateTimeHelper::toDateTime($expiryDate) ?: null;
        }
        $entry->enabled = (bool)$request->getBodyParam('enabled', $entry->enabled);
        $entry->enabledForSite = $entry->getSection()->getHasMultiSiteEntries()
            ? (bool)$request->getBodyParam('enabledForSite', $entry->enabledForSite)
            : true;
        $entry->title = $request->getBodyParam('title', $entry->title);
        if (!$entry->typeId) {
            // Default to the section's first entry type
            $entry->typeId = $entry->getSection()->getEntryTypes()[0]->id;
        }
        // Prevent the last entry type's field layout from being used
        $entry->fieldLayoutId = null;
        $fieldsLocation = $request->getParam('fieldsLocation', 'fields');
        $entry->setFieldValuesFromRequest($fieldsLocation);
        // Author
        $authorId = $request->getBodyParam('author', ($entry->authorId ?: Craft::$app->getUser()->getIdentity()->id));
        if (is_array($authorId)) {
            $authorId = $authorId[0] ?? null;
        }
        $entry->authorId = $authorId;
        // Parent
        if (($parentId = $request->getBodyParam('parentId')) !== null) {
            if (is_array($parentId)) {
                $parentId = reset($parentId) ?: '';
            }
            $entry->newParentId = $parentId ?: '';
        }
        // Revision notes
        $entry->revisionNotes = $request->getBodyParam('revisionNotes');
    }

    /**
     * Displays an entry.
     *
     * @param Entry $entry
     * @return Response
     * @throws ServerErrorHttpException if the entry doesn't have a URL for the site it's configured with, or if the entry's site ID is invalid
     */
    private function _showEntry(Entry $entry): Response
    {

        $sectionSiteSettings = $entry->getSection()->getSiteSettings();

        if (!isset($sectionSiteSettings[$entry->siteId]) || !$sectionSiteSettings[$entry->siteId]->hasUrls) {
            throw new ServerErrorHttpException('The entry ' . $entry->id . ' doesn’t have a URL for the site ' . $entry->siteId . '.');
        }

        $site = Craft::$app->getSites()->getSiteById($entry->siteId);
        if (!$site) {
            throw new ServerErrorHttpException('Invalid site ID: ' . $entry->siteId);
        }

        Craft::$app->getSites()->setCurrentSite($site);
        Craft::$app->language = $site->language;
        Craft::$app->set('locale', Craft::$app->getI18n()->getLocaleById($site->language));
        if (!$entry->postDate) {
            $entry->postDate = new DateTime();
        }

        // Have this entry override any freshly queried entries with the same ID/site ID
        Craft::$app->getElements()->setPlaceholderElement($entry);

        // Get meta
        $view = $this->getView();
        $view->getTwig()->disableStrictVariables();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        $meta = SEOMate::$plugin->meta->getContextMeta(\array_merge($view->getTwig()->getGlobals(), [
            'seomate' => [
                'element' => $entry,
                'config' => [
                    'cacheEnabled' => false,
                ],
            ],
        ]));

        // Render previews
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        return $this->renderTemplate('seomate/preview', [
            'entry' => $entry,
            'meta' => $meta,
        ]);
    }
}
