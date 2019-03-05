<?php
/**
 * 2007-2019 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;
use PrestaShop\Module\FacetedSearch\Product\SearchProvider;
use PrestaShop\Module\FacetedSearch\Filters\Converter;

class Ps_Facetedsearch extends Module implements WidgetInterface
{
    private $nbrProducts;
    private $psLayeredFullTree;

    public function __construct()
    {
        $this->name = 'ps_facetedsearch';
        $this->tab = 'front_office_features';
        $this->version = '3.0.0';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ajax = (bool) Tools::getValue('ajax');

        parent::__construct();

        $this->displayName = $this->trans('Faceted search', [], 'Modules.Facetedsearch.Admin');
        $this->description = $this->trans('Displays a block allowing multiple filters.', [], 'Modules.Facetedsearch.Admin');
        $this->psLayeredFullTree = Configuration::get('PS_LAYERED_FULL_TREE');
        $this->ps_versions_compliancy = ['min' => '1.7.1.0', 'max' => _PS_VERSION_];
    }

    protected function getDefaultFilters()
    {
        return [
            'layered_selection_subcategories' => [
                'label' => 'Sub-categories filter',
            ],
            'layered_selection_stock' => [
                'label' => 'Product stock filter',
            ],
            'layered_selection_condition' => [
                'label' => 'Product condition filter',
            ],
            'layered_selection_manufacturer' => [
                'label' => 'Product brand filter',
            ],
            'layered_selection_weight_slider' => [
                'label' => 'Product weight filter (slider)',
                'slider' => true,
            ],
            'layered_selection_price_slider' => [
                'label' => 'Product price filter (slider)',
                'slider' => true,
            ],
        ];
    }

    public function install()
    {
        $installed = parent::install() && $this->registerHook(
            [
                'categoryAddition',
                'categoryUpdate',
                'attributeGroupForm',
                'afterSaveAttributeGroup',
                'afterDeleteAttributeGroup',
                'featureForm',
                'afterDeleteFeature',
                'afterSaveFeature',
                'categoryDeletion',
                'afterSaveProduct',
                'postProcessAttributeGroup',
                'postProcessFeature',
                'featureValueForm',
                'postProcessFeatureValue',
                'afterDeleteFeatureValue',
                'afterSaveFeatureValue',
                'attributeForm',
                'postProcessAttribute',
                'afterDeleteAttribute',
                'afterSaveAttribute',
                'productSearchProvider',
                'displayLeftColumn',
            ]
        );

        // Installation failed (or hook registration) => uninstall the module
        if (!$installed) {
            $this->uninstall();

            return false;
        }

        Configuration::updateValue('PS_LAYERED_SHOW_QTIES', 1);
        Configuration::updateValue('PS_LAYERED_FULL_TREE', 1);
        Configuration::updateValue('PS_LAYERED_FILTER_PRICE_USETAX', 1);
        Configuration::updateValue('PS_LAYERED_FILTER_CATEGORY_DEPTH', 1);
        Configuration::updateValue('PS_ATTRIBUTE_ANCHOR_SEPARATOR', '-');
        Configuration::updateValue('PS_LAYERED_FILTER_PRICE_ROUNDING', 1);

        $this->psLayeredFullTree = 1;

        $this->rebuildLayeredStructure();
        $this->buildLayeredCategories();

        $productsCount = Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`');

        if ($productsCount < 20000) { // Lock template filter creation if too many products
            $this->rebuildLayeredCache();
        }

        self::installPriceIndexTable();
        $this->installIndexableAttributeTable();
        $this->installProductAttributeTable();

        if ($productsCount < 5000) {
            // Lock indexation if too many products

            self::fullPricesIndexProcess();
            $this->indexAttribute();
        }

        return true;
    }

    public function hookProductSearchProvider($params)
    {
        $query = $params['query'];
        // do something with query,
        // e.g. use $query->getIdCategory()
        // to choose a template for filters.
        // Query is an instance of:
        // PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery
        if ($query->getIdCategory()) {
            $this->context->controller->addJS($this->_path . 'views/dist/front.js');
            $this->context->controller->addCSS($this->_path . 'views/dist/front.css');

            return new SearchProvider($this);
        }

        return null;
    }

    public function uninstall()
    {
        /* Delete all configurations */
        Configuration::deleteByName('PS_LAYERED_SHOW_QTIES');
        Configuration::deleteByName('PS_LAYERED_FULL_TREE');
        Configuration::deleteByName('PS_LAYERED_INDEXED');
        Configuration::deleteByName('PS_LAYERED_FILTER_PRICE_USETAX');
        Configuration::deleteByName('PS_LAYERED_FILTER_CATEGORY_DEPTH');
        Configuration::deleteByName('PS_LAYERED_FILTER_PRICE_ROUNDING');

        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_price_index');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_indexable_attribute_group');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_indexable_feature');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_category');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_filter_block');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_filter');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_filter_shop');
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_product_attribute');

        return parent::uninstall();
    }

    private static function installPriceIndexTable()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_price_index`');

        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_price_index` (
            `id_product` INT  NOT NULL,
            `id_currency` INT NOT NULL,
            `id_shop` INT NOT NULL,
            `price_min` INT NOT NULL,
            `price_max` INT NOT NULL,
            `id_country` INT NOT NULL,
            PRIMARY KEY (`id_product`, `id_currency`, `id_shop`, `id_country`),
            INDEX `id_currency` (`id_currency`),
            INDEX `price_min` (`price_min`),
            INDEX `price_max` (`price_max`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    private function installIndexableAttributeTable()
    {
        // Attributes Groups
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_indexable_attribute_group`');
        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_indexable_attribute_group` (
            `id_attribute_group` INT NOT NULL,
            `indexable` BOOL NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_attribute_group`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'layered_indexable_attribute_group`
            SELECT id_attribute_group, 1 FROM `' . _DB_PREFIX_ . 'attribute_group`'
        );

        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value`');
        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value` (
            `id_attribute_group` INT NOT NULL,
            `id_lang` INT NOT NULL,
            `url_name` VARCHAR(128),
            `meta_title` VARCHAR(128),
            PRIMARY KEY (`id_attribute_group`, `id_lang`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        // Attributes
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value`');
        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value` (
            `id_attribute` INT NOT NULL,
            `id_lang` INT NOT NULL,
            `url_name` VARCHAR(128),
            `meta_title` VARCHAR(128),
            PRIMARY KEY (`id_attribute`, `id_lang`)
           )  ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        // Features
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_indexable_feature`');
        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_indexable_feature` (
            `id_feature` INT NOT NULL,
            `indexable` BOOL NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_feature`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'layered_indexable_feature`
            SELECT id_feature, 1 FROM `' . _DB_PREFIX_ . 'feature`'
        );

        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value`');
        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value` (
            `id_feature` INT NOT NULL,
            `id_lang` INT NOT NULL,
            `url_name` VARCHAR(128) NOT NULL,
            `meta_title` VARCHAR(128),
            PRIMARY KEY (`id_feature`, `id_lang`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        // Features values
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value`');
        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value` (
            `id_feature_value` INT NOT NULL,
            `id_lang` INT NOT NULL,
            `url_name` VARCHAR(128),
            `meta_title` VARCHAR(128),
            PRIMARY KEY (`id_feature_value`, `id_lang`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    /**
     * create table product attribute.
     */
    public function installProductAttributeTable()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'layered_product_attribute`');
        Db::getInstance()->execute(
            'CREATE TABLE `' . _DB_PREFIX_ . 'layered_product_attribute` (
            `id_attribute` int(10) unsigned NOT NULL,
            `id_product` int(10) unsigned NOT NULL,
            `id_attribute_group` int(10) unsigned NOT NULL DEFAULT "0",
            `id_shop` int(10) unsigned NOT NULL DEFAULT "1",
            PRIMARY KEY (`id_attribute`, `id_product`, `id_shop`),
            UNIQUE KEY `id_attribute_group` (`id_attribute_group`,`id_attribute`,`id_product`, `id_shop`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    /**
     * Attributes group
     */
    public function hookAfterSaveAttributeGroup($params)
    {
        if (!$params['id_attribute_group'] || Tools::getValue('layered_indexable') === false) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']
        );
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']
        );

        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_attribute_group (`id_attribute_group`, `indexable`)
VALUES (' . (int) $params['id_attribute_group'] . ', ' . (int) Tools::getValue('layered_indexable') . ')'
        );

        foreach (Language::getLanguages(false) as $language) {
            $seoUrl = Tools::getValue('url_name_' . (int) $language['id_lang']);

            if (empty($seoUrl)) {
                $seoUrl = Tools::getValue('name_' . (int) $language['id_lang']);
            }

            Db::getInstance()->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
                (`id_attribute_group`, `id_lang`, `url_name`, `meta_title`)
                VALUES (
                ' . (int) $params['id_attribute_group'] . ', ' . (int) $language['id_lang'] . ',
                \'' . pSQL(Tools::link_rewrite($seoUrl)) . '\',
                \'' . pSQL(Tools::getValue('meta_title_' . (int) $language['id_lang']), true) . '\')'
            );
        }
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookAfterDeleteAttributeGroup($params)
    {
        if (!$params['id_attribute_group']) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']
        );
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']
        );
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookPostProcessAttributeGroup($params)
    {
        $this->checkLinksRewrite($params);
    }

    public function hookAttributeGroupForm($params)
    {
        $values = [];
        $isIndexable = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT `indexable`
            FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']
        );

        if ($isIndexable === false) {
            $isIndexable = true;
        }

        if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT `url_name`, `meta_title`, `id_lang` FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']
        )) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }

        $this->context->smarty->assign([
            'languages' => Language::getLanguages(false),
            'default_form_language' => (int) $this->context->controller->default_form_language,
            'values' => $values,
            'is_indexable' => (bool) $isIndexable,
        ]);

        return $this->display(__FILE__, 'attribute_group_form.tpl');
    }

    //ATTRIBUTES
    public function hookAfterSaveAttribute($params)
    {
        if (!$params['id_attribute']) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
            WHERE `id_attribute` = ' . (int) $params['id_attribute']
        );

        foreach (Language::getLanguages(false) as $language) {
            $seoUrl = Tools::getValue('url_name_' . (int) $language['id_lang']);

            if (empty($seoUrl)) {
                $seoUrl = Tools::getValue('name_' . (int) $language['id_lang']);
            }

            Db::getInstance()->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
                (`id_attribute`, `id_lang`, `url_name`, `meta_title`)
                VALUES (
                ' . (int) $params['id_attribute'] . ', ' . (int) $language['id_lang'] . ',
                \'' . pSQL(Tools::link_rewrite($seoUrl)) . '\',
                \'' . pSQL(Tools::getValue('meta_title_' . (int) $language['id_lang']), true) . '\')'
            );
        }
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookAfterDeleteAttribute($params)
    {
        if (!$params['id_attribute']) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
            WHERE `id_attribute` = ' . (int) $params['id_attribute']
        );
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookPostProcessAttribute($params)
    {
        $this->checkLinksRewrite($params);
    }

    public function hookAttributeForm($params)
    {
        $values = [];

        if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT `url_name`, `meta_title`, `id_lang`
            FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
            WHERE `id_attribute` = ' . (int) $params['id_attribute']
        )) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }

        $this->context->smarty->assign([
            'languages' => Language::getLanguages(false),
            'default_form_language' => (int) $this->context->controller->default_form_language,
            'values' => $values,
        ]);

        return $this->display(__FILE__, 'attribute_form.tpl');
    }

    //FEATURES
    public function hookAfterSaveFeature($params)
    {
        if (!$params['id_feature'] || Tools::getValue('layered_indexable') === false) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature
            WHERE `id_feature` = ' . (int) $params['id_feature']
        );
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value
            WHERE `id_feature` = ' . (int) $params['id_feature']
        );

        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature
            (`id_feature`, `indexable`)
            VALUES (' . (int) $params['id_feature'] . ', ' . (int) Tools::getValue('layered_indexable') . ')'
        );

        foreach (Language::getLanguages(false) as $language) {
            $seoUrl = Tools::getValue('url_name_' . (int) $language['id_lang']);

            if (empty($seoUrl)) {
                $seoUrl = Tools::getValue('name_' . (int) $language['id_lang']);
            }

            Db::getInstance()->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value
                (`id_feature`, `id_lang`, `url_name`, `meta_title`)
                VALUES (
                ' . (int) $params['id_feature'] . ', ' . (int) $language['id_lang'] . ',
                \'' . pSQL(Tools::link_rewrite($seoUrl)) . '\',
                \'' . pSQL(Tools::getValue('meta_title_' . (int) $language['id_lang']), true) . '\')'
            );
        }

        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookAfterDeleteFeature($params)
    {
        if (!$params['id_feature']) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature
            WHERE `id_feature` = ' . (int) $params['id_feature']
        );
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookPostProcessFeature($params)
    {
        $this->checkLinksRewrite($params);
    }

    public function hookFeatureForm($params)
    {
        $values = [];
        $isIndexable = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT `indexable`
            FROM ' . _DB_PREFIX_ . 'layered_indexable_feature
            WHERE `id_feature` = ' . (int) $params['id_feature']
        );

        if ($isIndexable === false) {
            $isIndexable = true;
        }

        if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT `url_name`, `meta_title`, `id_lang` FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value
            WHERE `id_feature` = ' . (int) $params['id_feature']
        )) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }

        $this->context->smarty->assign([
            'languages' => Language::getLanguages(false),
            'default_form_language' => (int) $this->context->controller->default_form_language,
            'values' => $values,
            'is_indexable' => (bool) $isIndexable,
        ]);

        return $this->display(__FILE__, 'feature_form.tpl');
    }

    //FEATURES VALUE
    public function hookAfterSaveFeatureValue($params)
    {
        if (!$params['id_feature_value']) {
            return;
        }

        //Removing all indexed language data for this attribute value id
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
            WHERE `id_feature_value` = ' . (int) $params['id_feature_value']
        );

        foreach (Language::getLanguages(false) as $language) {
            $seoUrl = Tools::getValue('url_name_' . (int) $language['id_lang']);

            if (empty($seoUrl)) {
                $seoUrl = Tools::getValue('name_' . (int) $language['id_lang']);
            }

            Db::getInstance()->execute(
                'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
                (`id_feature_value`, `id_lang`, `url_name`, `meta_title`)
                VALUES (
                ' . (int) $params['id_feature_value'] . ', ' . (int) $language['id_lang'] . ',
                \'' . pSQL(Tools::link_rewrite($seoUrl)) . '\',
                \'' . pSQL(Tools::getValue('meta_title_' . (int) $language['id_lang']), true) . '\')'
            );
        }
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookAfterDeleteFeatureValue($params)
    {
        if (!$params['id_feature_value']) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
            WHERE `id_feature_value` = ' . (int) $params['id_feature_value']
        );
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookPostProcessFeatureValue($params)
    {
        $this->checkLinksRewrite($params);
    }

    public function hookFeatureValueForm($params)
    {
        $values = [];

        if ($result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT `url_name`, `meta_title`, `id_lang`
            FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
            WHERE `id_feature_value` = ' . (int) $params['id_feature_value']
        )) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }

        $this->context->smarty->assign([
            'languages' => Language::getLanguages(false),
            'default_form_language' => (int) $this->context->controller->default_form_language,
            'values' => $values,
        ]);

        return $this->display(__FILE__, 'feature_value_form.tpl');
    }

    public function hookAfterSaveProduct($params)
    {
        if (!$params['id_product']) {
            return;
        }

        self::indexProductPrices((int) $params['id_product']);
        $this->indexAttribute((int) $params['id_product']);
        $this->invalidateLayeredFilterBlockCache();
    }

    public function invalidateLayeredFilterBlockCache()
    {
        Db::getInstance()->execute('TRUNCATE TABLE ' . _DB_PREFIX_ . 'layered_filter_block');
    }

    public function renderWidget($hookName, array $configuration)
    {
        $this->smarty->assign($this->getWidgetVariables($hookName, $configuration));

        return $this->fetch('module:ps_facetedsearch/ps_facetedsearch.tpl');
    }

    public function getWidgetVariables($hookName, array $configuration)
    {
        return [];
    }

    public function hookCategoryAddition($params)
    {
        $this->rebuildLayeredCache([], [(int) $params['category']->id]);
        $this->invalidateLayeredFilterBlockCache();
    }

    public function hookCategoryUpdate($params)
    {
        /**
         * The category status might (active, inactive) have changed,
         * we have to update the layered cache table structure
         */
        if (isset($params['category']) && !$params['category']->active) {
            $this->hookCategoryDeletion($params);
            $this->invalidateLayeredFilterBlockCache();
        }
    }

    public function hookCategoryDeletion($params)
    {
        $layeredFilterList = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'layered_filter'
        );

        foreach ($layeredFilterList as $layeredFilter) {
            $data = Tools::unSerialize($layeredFilter['filters']);

            if (in_array((int) $params['category']->id, $data['categories'])) {
                unset($data['categories'][array_search((int) $params['category']->id, $data['categories'])]);
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'layered_filter`
                    SET `filters` = \'' . pSQL(serialize($data)) . '\'
                    WHERE `id_layered_filter` = ' . (int) $layeredFilter['id_layered_filter']
                );
            }
        }

        $this->invalidateLayeredFilterBlockCache();
        $this->buildLayeredCategories();
    }

    /*
     * Generate data product attribute
     */
    public function indexAttribute($idProduct = null)
    {
        if (is_null($idProduct)) {
            Db::getInstance()->execute('TRUNCATE ' . _DB_PREFIX_ . 'layered_product_attribute');
        } else {
            Db::getInstance()->execute(
                'DELETE FROM ' . _DB_PREFIX_ . 'layered_product_attribute
                WHERE id_product = ' . (int) $idProduct
            );
        }

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'layered_product_attribute` (`id_attribute`, `id_product`, `id_attribute_group`, `id_shop`)
            SELECT pac.id_attribute, pa.id_product, ag.id_attribute_group, product_attribute_shop.`id_shop`
            FROM ' . _DB_PREFIX_ . 'product_attribute pa' .
            Shop::addSqlAssociation('product_attribute', 'pa') . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON pac.id_product_attribute = pa.id_product_attribute
            INNER JOIN ' . _DB_PREFIX_ . 'attribute a ON (a.id_attribute = pac.id_attribute)
            INNER JOIN ' . _DB_PREFIX_ . 'attribute_group ag ON ag.id_attribute_group = a.id_attribute_group
            ' . (is_null($idProduct) ? '' : 'AND pa.id_product = ' . (int) $idProduct) . '
            GROUP BY a.id_attribute, pa.id_product , product_attribute_shop.`id_shop`'
        );

        return 1;
    }

    /*
     * $cursor $cursor in order to restart indexing from the last state
     */
    public static function fullPricesIndexProcess($cursor = 0, $ajax = false, $smart = false)
    {
        if ($cursor == 0 && !$smart) {
            self::installPriceIndexTable();
        }

        return self::indexPrices($cursor, true, $ajax, $smart);
    }

    /*
     * $cursor $cursor in order to restart indexing from the last state
     */
    public static function pricesIndexProcess($cursor = 0, $ajax = false)
    {
        return self::indexPrices($cursor, false, $ajax);
    }

    private static function indexPrices($cursor = 0, $full = false, $ajax = false, $smart = false)
    {
        if ($full) {
            $nbProducts = (int) Db::getInstance()->getValue(
                'SELECT count(DISTINCT p.`id_product`)
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`active` = 1 AND ps.`visibility` IN ("both", "catalog"))'
            );
        } else {
            $nbProducts = (int) Db::getInstance()->getValue(
                'SELECT COUNT(DISTINCT p.`id_product`) FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`active` = 1 AND ps.`visibility` IN ("both", "catalog"))
                LEFT JOIN  `' . _DB_PREFIX_ . 'layered_price_index` psi ON (psi.id_product = p.id_product)
                WHERE psi.id_product IS NULL'
            );
        }

        $maxExecutiontime = @ini_get('max_execution_time');
        if ($maxExecutiontime > 5 || $maxExecutiontime <= 0) {
            $maxExecutiontime = 5;
        }

        $startTime = microtime(true);

        $indexedProducts = 0;
        $length = 100;
        if (function_exists('memory_get_peak_usage')) {
            do {
                $lastCursor = $cursor;
                $cursor = (int) self::indexPricesUnbreakable((int) $cursor, $full, $smart, $length);
                if ($cursor == 0) {
                    $lastCursor = $cursor;
                    break;
                }
                $time_elapsed = microtime(true) - $startTime;
            } while ($cursor < $nbProducts
                && (Tools::getMemoryLimit() == -1 || Tools::getMemoryLimit() > memory_get_peak_usage())
                && $time_elapsed < $maxExecutiontime);
        } else {
            do {
                $lastCursor = $cursor;
                $cursor = (int) self::indexPricesUnbreakable((int) $cursor, $full, $smart, $length);
                if ($cursor == 0) {
                    $lastCursor = $cursor;
                    break;
                }
                $time_elapsed = microtime(true) - $startTime;
                $indexedProducts += $length;
            } while ($cursor != $lastCursor && $time_elapsed < $maxExecutiontime);
        }
        if (($nbProducts > 0 && !$full || $cursor != $lastCursor && $full) && !$ajax) {
            $token = substr(Tools::encrypt('ps_facetedsearch/index'), 0, 10);
            if (Tools::usingSecureMode()) {
                $domain = Tools::getShopDomainSsl(true);
            } else {
                $domain = Tools::getShopDomain(true);
            }

            if (!Tools::file_get_contents($domain . __PS_BASE_URI__ . 'modules/ps_facetedsearch/ps_facetedsearch-price-indexer.php?token=' . $token . '&cursor=' . (int) $cursor . '&full=' . (int) $full)) {
                self::indexPrices((int) $cursor, (int) $full);
            }

            return $cursor;
        }

        if ($ajax && $nbProducts > 0 && $cursor != $lastCursor && $full) {
            return json_encode([
                'cursor' => $cursor,
                'count' => $indexedProducts,
            ]);
        }

        if ($ajax && $nbProducts > 0 && !$full) {
            return json_encode([
                'cursor' => $cursor,
                'count' => $nbProducts,
            ]);
        }

        Configuration::updateGlobalValue('PS_LAYERED_INDEXED', 1);

        if ($ajax) {
            return json_encode([
                'result' => 'ok',
            ]);
        }

        return -1;
    }

    /**
     * @param $cursor int last indexed id_product
     * @param bool $full
     * @param bool $smart
     * @param int $length nb of products to index
     *
     * @return int
     */
    private static function indexPricesUnbreakable($cursor, $full = false, $smart = false, $length = 100)
    {
        if (null === $cursor) {
            $cursor = 0;
        }

        if ($full) {
            $query = 'SELECT p.`id_product`
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`active` = 1 AND ps.`visibility` IN ("both", "catalog"))
                WHERE p.id_product > ' . (int) $cursor . '
                GROUP BY p.`id_product`
                ORDER BY p.`id_product` LIMIT 0,' . (int) $length;
        } else {
            $query = 'SELECT p.`id_product`
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`active` = 1 AND ps.`visibility` IN ("both", "catalog"))
                LEFT JOIN  `' . _DB_PREFIX_ . 'layered_price_index` psi ON (psi.id_product = p.id_product)
                WHERE psi.id_product IS NULL
                GROUP BY p.`id_product`
                ORDER BY p.`id_product` LIMIT 0,' . (int) $length;
        }

        $lastIdProduct = 0;
        foreach (Db::getInstance()->executeS($query) as $product) {
            self::indexProductPrices((int) $product['id_product'], ($smart && $full));
            $lastIdProduct = $product['id_product'];
        }

        return (int) $lastIdProduct;
    }

    /**
     * Index product prices
     *
     * @param int $idProduct
     * @param bool $smart
     */
    public static function indexProductPrices($idProduct, $smart = true)
    {
        static $groups = null;

        if ($groups === null) {
            $groups = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT id_group FROM `' . _DB_PREFIX_ . 'group_reduction`');
            if (!$groups) {
                $groups = [];
            }
        }

        $shopList = Shop::getShops(false, null, true);

        foreach ($shopList as $idShop) {
            $currencyList = Currency::getCurrencies(false, 1, new Shop($idShop));

            $minPrice = [];
            $maxPrice = [];

            if ($smart) {
                Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'layered_price_index` WHERE `id_product` = ' . (int) $idProduct . ' AND `id_shop` = ' . (int) $idShop);
            }

            if (!Configuration::get('PS_LAYERED_FILTER_PRICE_USETAX')) {
                $taxRatesByCountry = [['rate' => 0, 'id_country' => 0]];
            } else {
                $taxRatesByCountry = Db::getInstance()->executeS(
                    'SELECT t.rate rate, tr.id_country
                    FROM `' . _DB_PREFIX_ . 'product_shop` p
                    LEFT JOIN `' . _DB_PREFIX_ . 'tax_rules_group` trg ON (trg.id_tax_rules_group = p.id_tax_rules_group AND p.id_shop = ' . (int) $idShop . ')
                    LEFT JOIN `' . _DB_PREFIX_ . 'tax_rule` tr ON (tr.id_tax_rules_group = trg.id_tax_rules_group)
                    LEFT JOIN `' . _DB_PREFIX_ . 'tax` t ON (t.id_tax = tr.id_tax AND t.active = 1)
                    JOIN `' . _DB_PREFIX_ . 'country` c ON (tr.id_country=c.id_country AND c.active = 1)
                    WHERE id_product = ' . (int) $idProduct . '
                    GROUP BY id_product, tr.id_country'
                );
            }

            $productMinPrices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                'SELECT id_shop, id_currency, id_country, id_group, from_quantity
                FROM `' . _DB_PREFIX_ . 'specific_price`
                WHERE id_product = ' . (int) $idProduct . ' AND id_shop IN (0,' . (int) $idShop . ')'
            );

            $countries = Country::getCountries(Context::getContext()->language->id, true, false, false);
            foreach ($countries as $country) {
                $idCountry = $country['id_country'];

                // Get price by currency & country, without reduction!
                foreach ($currencyList as $currency) {
                    $price = Product::priceCalculation(
                        $idShop,
                        (int) $idProduct,
                        null,
                        $idCountry,
                        null,
                        null,
                        $currency['id_currency'],
                        null,
                        null,
                        false,
                        6, // Decimals
                        false,
                        false,
                        true,
                        $specificPriceOutput,
                        true
                    );

                    $minPrice[$idCountry][$currency['id_currency']] = $price;
                    $maxPrice[$idCountry][$currency['id_currency']] = $price;
                }

                foreach ($productMinPrices as $specificPrice) {
                    foreach ($currencyList as $currency) {
                        if ($specificPrice['id_currency'] &&
                            $specificPrice['id_currency'] != $currency['id_currency']
                        ) {
                            continue;
                        }

                        $price = Product::priceCalculation(
                            $idShop,
                            (int) $idProduct,
                            null,
                            $idCountry,
                            null,
                            null,
                            $currency['id_currency'],
                            (($specificPrice['id_group'] == 0) ? null : $specificPrice['id_group']),
                            $specificPrice['from_quantity'],
                            false,
                            6,
                            false,
                            true,
                            true,
                            $specificPriceOutput,
                            true
                        );

                        if ($price > $maxPrice[$idCountry][$currency['id_currency']]) {
                            $maxPrice[$idCountry][$currency['id_currency']] = $price;
                        }

                        if ($price == 0) {
                            continue;
                        }

                        if (null === $minPrice[$idCountry][$currency['id_currency']] || $price < $minPrice[$idCountry][$currency['id_currency']]) {
                            $minPrice[$idCountry][$currency['id_currency']] = $price;
                        }
                    }
                }

                foreach ($groups as $group) {
                    foreach ($currencyList as $currency) {
                        $price = Product::priceCalculation(
                            $idShop,
                            (int) $idProduct,
                            (int) $idCountry,
                            null,
                            null,
                            null,
                            (int) $currency['id_currency'],
                            (int) $group['id_group'],
                            null,
                            false,
                            6,
                            false,
                            true,
                            true,
                            $specificPriceOutput,
                            true
                        );

                        if (!isset($maxPrice[$idCountry][$currency['id_currency']])) {
                            $maxPrice[$idCountry][$currency['id_currency']] = 0;
                        }

                        if (!isset($minPrice[$idCountry][$currency['id_currency']])) {
                            $minPrice[$idCountry][$currency['id_currency']] = null;
                        }

                        if ($price > $maxPrice[$idCountry][$currency['id_currency']]) {
                            $maxPrice[$idCountry][$currency['id_currency']] = $price;
                        }

                        if ($price == 0) {
                            continue;
                        }

                        if (null === $minPrice[$idCountry][$currency['id_currency']] || $price < $minPrice[$idCountry][$currency['id_currency']]) {
                            $minPrice[$idCountry][$currency['id_currency']] = $price;
                        }
                    }
                }
            }

            $values = [];
            foreach ($taxRatesByCountry as $taxRateByCountry) {
                $taxRate = $taxRateByCountry['rate'];
                $idCountry = $taxRateByCountry['id_country'];
                foreach ($currencyList as $currency) {
                    $minPriceValue = array_key_exists($idCountry, $minPrice) ? $minPrice[$idCountry][$currency['id_currency']] : 0;
                    $maxPriceValue = array_key_exists($idCountry, $maxPrice) ? $maxPrice[$idCountry][$currency['id_currency']] : 0;
                    $values[] = '(' . (int) $idProduct . ',
                        ' . (int) $currency['id_currency'] . ',
                        ' . $idShop . ',
                        ' . (int) Tools::ps_round($minPriceValue * (100 + $taxRate) / 100, 0) . ',
                        ' . (int) Tools::ps_round($maxPriceValue * (100 + $taxRate) / 100, 0) . ',
                        ' . (int) $idCountry . ')';
                }
            }

            Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'layered_price_index` (id_product, id_currency, id_shop, price_min, price_max, id_country)
                VALUES ' . implode(',', $values) . '
                ON DUPLICATE KEY UPDATE id_product = id_product' // Avoid duplicate keys
            );
        }
    }

    public function getContent()
    {
        global $cookie;
        $message = '';

        if (Tools::isSubmit('SubmitFilter')) {
            if (!Tools::getValue('layered_tpl_name')) {
                $message = $this->displayError($this->trans('Filter template name required (cannot be empty)', [], 'Modules.Facetedsearch.Admin'));
            } elseif (!Tools::getValue('categoryBox')) {
                $message = $this->displayError($this->trans('You must select at least one category.', [], 'Modules.Facetedsearch.Admin'));
            } else {
                if (Tools::getValue('id_layered_filter')) {
                    Db::getInstance()->execute(
                        'DELETE FROM ' . _DB_PREFIX_ . 'layered_filter
                        WHERE id_layered_filter = ' . (int) Tools::getValue('id_layered_filter')
                    );
                    $this->buildLayeredCategories();
                }

                if (Tools::getValue('scope') == 1) {
                    Db::getInstance()->execute('TRUNCATE TABLE ' . _DB_PREFIX_ . 'layered_filter');
                    $categories = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                        'SELECT id_category FROM ' . _DB_PREFIX_ . 'category'
                    );

                    foreach ($categories as $category) {
                        $_POST['categoryBox'][] = (int) $category['id_category'];
                    }
                }

                $idLayeredFilter = (int) Tools::getValue('id_layered_filter');

                if (!$idLayeredFilter) {
                    $idLayeredFilter = (int) Db::getInstance()->Insert_ID();
                }

                $shopList = [];

                if (isset($_POST['checkBoxShopAsso_layered_filter'])) {
                    foreach ($_POST['checkBoxShopAsso_layered_filter'] as $idShop => $row) {
                        $assos[] = ['id_object' => (int) $idLayeredFilter, 'id_shop' => (int) $idShop];
                        $shopList[] = (int) $idShop;
                    }
                } else {
                    $shopList = [Context::getContext()->shop->id];
                }

                Db::getInstance()->execute(
                    'DELETE FROM ' . _DB_PREFIX_ . 'layered_filter_shop WHERE `id_layered_filter` = ' . (int) $idLayeredFilter
                );

                if (count($_POST['categoryBox'])) {
                    /* Clean categoryBox before use */
                    if (isset($_POST['categoryBox']) && is_array($_POST['categoryBox'])) {
                        foreach ($_POST['categoryBox'] as &$categoryBoxTmp) {
                            $categoryBoxTmp = (int) $categoryBoxTmp;
                        }
                    }

                    $filterValues = [];

                    foreach ($_POST['categoryBox'] as $idc) {
                        $filterValues['categories'][] = (int) $idc;
                    }

                    $filterValues['shop_list'] = $shopList;
                    $values = false;

                    foreach ($_POST['categoryBox'] as $idCategoryLayered) {
                        foreach ($_POST as $key => $value) {
                            if (substr($key, 0, 17) == 'layered_selection' && $value == 'on') {
                                $values = true;
                                $type = 0;
                                $limit = 0;

                                if (Tools::getValue($key . '_filter_type')) {
                                    $type = Tools::getValue($key . '_filter_type');
                                }
                                if (Tools::getValue($key . '_filter_show_limit')) {
                                    $limit = Tools::getValue($key . '_filter_show_limit');
                                }

                                $filterValues[$key] = [
                                    'filter_type' => (int) $type,
                                    'filter_show_limit' => (int) $limit,
                                ];
                            }
                        }
                    }

                    $valuesToInsert = [
                        'name' => pSQL(Tools::getValue('layered_tpl_name')),
                        'filters' => pSQL(serialize($filterValues)),
                        'n_categories' => (int) count($filterValues['categories']),
                        'date_add' => date('Y-m-d H:i:s'), ];

                    if (isset($_POST['id_layered_filter']) && $_POST['id_layered_filter']) {
                        $valuesToInsert['id_layered_filter'] = (int) Tools::getValue('id_layered_filter');
                    }

                    $idLayeredFilter = isset($valuesToInsert['id_layered_filter']) ? (int) $valuesToInsert['id_layered_filter'] : 'NULL';
                    $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'layered_filter (name, filters, n_categories, date_add, id_layered_filter) VALUES ("' . pSQL($valuesToInsert['name']) . '", "' . $valuesToInsert['filters'] . '",' . (int) $valuesToInsert['n_categories'] . ',"' . pSQL($valuesToInsert['date_add']) . '",' . $idLayeredFilter . ')';
                    Db::getInstance()->execute($sql);
                    $idLayeredFilter = (int) Db::getInstance()->Insert_ID();

                    if (isset($assos)) {
                        foreach ($assos as $asso) {
                            Db::getInstance()->execute(
                                'INSERT INTO ' . _DB_PREFIX_ . 'layered_filter_shop (`id_layered_filter`, `id_shop`)
    VALUES(' . $idLayeredFilter . ', ' . (int) $asso['id_shop'] . ')'
                            );
                        }
                    }

                    $this->buildLayeredCategories();
                    $message = $this->displayConfirmation($this->trans('Your filter', [], 'Modules.Facetedsearch.Admin') . ' "' . Tools::safeOutput(Tools::getValue('layered_tpl_name')) . '" ' .
                        ((isset($_POST['id_layered_filter']) && $_POST['id_layered_filter']) ? $this->trans('was updated successfully.', [], 'Modules.Facetedsearch.Admin') : $this->trans('was added successfully.', [], 'Modules.Facetedsearch.Admin')));
                }
            }
        } elseif (Tools::isSubmit('submitLayeredSettings')) {
            Configuration::updateValue('PS_LAYERED_SHOW_QTIES', (int) Tools::getValue('ps_layered_show_qties'));
            Configuration::updateValue('PS_LAYERED_FULL_TREE', (int) Tools::getValue('psLayeredFullTree'));
            Configuration::updateValue('PS_LAYERED_FILTER_PRICE_USETAX', (int) Tools::getValue('ps_layered_filter_price_usetax'));
            Configuration::updateValue('PS_LAYERED_FILTER_CATEGORY_DEPTH', (int) Tools::getValue('ps_layered_filter_category_depth'));
            Configuration::updateValue('PS_LAYERED_FILTER_PRICE_ROUNDING', (int) Tools::getValue('ps_layered_filter_price_rounding'));

            $this->psLayeredFullTree = (int) Tools::getValue('psLayeredFullTree');

            $message = '<div class="alert alert-success">' . $this->trans('Settings saved successfully', [], 'Modules.Facetedsearch.Admin') . '</div>';
        } elseif (Tools::getValue('deleteFilterTemplate')) {
            $layered_values = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
                'SELECT filters
                FROM ' . _DB_PREFIX_ . 'layered_filter
                WHERE id_layered_filter = ' . (int) Tools::getValue('id_layered_filter')
            );

            if ($layered_values) {
                Db::getInstance()->execute(
                    'DELETE FROM ' . _DB_PREFIX_ . 'layered_filter
                    WHERE id_layered_filter = ' . (int) Tools::getValue('id_layered_filter') . ' LIMIT 1'
                );
                $this->buildLayeredCategories();
                $message = $this->displayConfirmation($this->trans('Filter template deleted, categories updated (reverted to default Filter template).', [], 'Modules.Facetedsearch.Admin'));
            } else {
                $message = $this->displayError($this->trans('Filter template not found', [], 'Modules.Facetedsearch.Admin'));
            }
        }

        $categoryBox = [];
        $attributeGroups = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT ag.id_attribute_group, ag.is_color_group, agl.name, COUNT(DISTINCT(a.id_attribute)) n
            FROM ' . _DB_PREFIX_ . 'attribute_group ag
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON (agl.id_attribute_group = ag.id_attribute_group)
            LEFT JOIN ' . _DB_PREFIX_ . 'attribute a ON (a.id_attribute_group = ag.id_attribute_group)
            WHERE agl.id_lang = ' . (int) $cookie->id_lang . '
            GROUP BY ag.id_attribute_group'
        );

        $features = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT fl.id_feature, fl.name, COUNT(DISTINCT(fv.id_feature_value)) n
            FROM ' . _DB_PREFIX_ . 'feature_lang fl
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value fv ON (fv.id_feature = fl.id_feature)
            WHERE (fv.custom IS NULL OR fv.custom = 0) AND fl.id_lang = ' . (int) $cookie->id_lang . '
            GROUP BY fl.id_feature'
        );

        if (Shop::isFeatureActive() && count(Shop::getShops(true, null, true)) > 1) {
            $helper = new HelperForm();
            $helper->id = Tools::getValue('id_layered_filter', null);
            $helper->table = 'layered_filter';
            $helper->identifier = 'id_layered_filter';
            $this->context->smarty->assign('asso_shops', $helper->renderAssoShop());
        }

        $treeCategoriesHelper = new HelperTreeCategories('categories-treeview');
        $treeCategoriesHelper->setRootCategory((Shop::getContext() == Shop::CONTEXT_SHOP ? Category::getRootCategory()->id_category : 0))
                                                                     ->setUseCheckBox(true);

        $moduleUrl = Tools::getProtocol(Tools::usingSecureMode()) . $_SERVER['HTTP_HOST'] . $this->getPathUri();

        if (method_exists($this->context->controller, 'addJquery')) {
            $this->context->controller->addJS(_PS_JS_DIR_ . 'jquery/plugins/jquery.sortable.js');
        }

        $this->context->controller->addJS($this->_path . 'views/dist/back.js');
        $this->context->controller->addCSS($this->_path . 'views/dist/back.css');

        if (Tools::getValue('add_new_filters_template')) {
            $this->context->smarty->assign([
                'current_url' => $this->context->link->getAdminLink('AdminModules') . '&configure=ps_facetedsearch&tab_module=front_office_features&module_name=ps_facetedsearch',
                'uri' => $this->getPathUri(),
                'id_layered_filter' => 0,
                'template_name' => sprintf($this->trans('My template - %s', [], 'Modules.Facetedsearch.Admin'), date('Y-m-d')),
                'attribute_groups' => $attributeGroups,
                'features' => $features,
                'total_filters' => 6 + count($attributeGroups) + count($features),
            ]);

            $this->context->smarty->assign('categories_tree', $treeCategoriesHelper->render());

            return $this->display(__FILE__, 'views/templates/admin/add.tpl');
        }

        if (Tools::getValue('edit_filters_template')) {
            $template = Db::getInstance()->getRow(
                'SELECT *
                FROM `' . _DB_PREFIX_ . 'layered_filter`
                WHERE id_layered_filter = ' . (int) Tools::getValue('id_layered_filter')
            );

            $filters = Tools::unSerialize($template['filters']);
            $treeCategoriesHelper->setSelectedCategories($filters['categories']);
            $this->context->smarty->assign('categories_tree', $treeCategoriesHelper->render());

            $selectShops = $filters['shop_list'];
            unset($filters['categories']);
            unset($filters['shop_list']);

            $this->context->smarty->assign([
                'current_url' => $this->context->link->getAdminLink('AdminModules') . '&configure=ps_facetedsearch&tab_module=front_office_features&module_name=ps_facetedsearch',
                'uri' => $this->getPathUri(),
                'id_layered_filter' => (int) Tools::getValue('id_layered_filter'),
                'template_name' => $template['name'],
                'attribute_groups' => $attributeGroups,
                'features' => $features,
                'filters' => $filters,
                'total_filters' => 6 + count($attributeGroups) + count($features),
                'default_filters' => $this->getDefaultFilters(),
            ]);

            return $this->display(__FILE__, 'views/templates/admin/view.tpl');
        }

        $this->context->smarty->assign([
            'message' => $message,
            'uri' => $this->getPathUri(),
            'PS_LAYERED_INDEXED' => Configuration::getGlobalValue('PS_LAYERED_INDEXED'),
            'current_url' => Tools::safeOutput(preg_replace('/&deleteFilterTemplate=[0-9]*&id_layered_filter=[0-9]*/', '', $_SERVER['REQUEST_URI'])),
            'id_lang' => Context::getContext()->cookie->id_lang,
            'token' => substr(Tools::encrypt('ps_facetedsearch/index'), 0, 10),
            'base_folder' => urlencode(_PS_ADMIN_DIR_),
            'price_indexer_url' => $moduleUrl . 'ps_facetedsearch-price-indexer.php' . '?token=' . substr(Tools::encrypt('ps_facetedsearch/index'), 0, 10),
            'full_price_indexer_url' => $moduleUrl . 'ps_facetedsearch-price-indexer.php' . '?token=' . substr(Tools::encrypt('ps_facetedsearch/index'), 0, 10) . '&full=1',
            'attribute_indexer_url' => $moduleUrl . 'ps_facetedsearch-attribute-indexer.php' . '?token=' . substr(Tools::encrypt('ps_facetedsearch/index'), 0, 10),
            'filters_templates' => Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'layered_filter ORDER BY date_add DESC'),
            'show_quantities' => Configuration::get('PS_LAYERED_SHOW_QTIES'),
            'full_tree' => $this->psLayeredFullTree,
            'category_depth' => Configuration::get('PS_LAYERED_FILTER_CATEGORY_DEPTH'),
            'price_use_tax' => (bool) Configuration::get('PS_LAYERED_FILTER_PRICE_USETAX'),
            'limit_warning' => $this->displayLimitPostWarning(21 + count($attributeGroups) * 3 + count($features) * 3),
            'price_use_rounding' => (bool) Configuration::get('PS_LAYERED_FILTER_PRICE_ROUNDING'),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/manage.tpl');
    }

    public function displayLimitPostWarning($count)
    {
        $return = [];
        if ((ini_get('suhosin.post.max_vars') && ini_get('suhosin.post.max_vars') < $count) || (ini_get('suhosin.request.max_vars') && ini_get('suhosin.request.max_vars') < $count)) {
            $return['error_type'] = 'suhosin';
            $return['post.max_vars'] = ini_get('suhosin.post.max_vars');
            $return['request.max_vars'] = ini_get('suhosin.request.max_vars');
            $return['needed_limit'] = $count + 100;
        } elseif (ini_get('max_input_vars') && ini_get('max_input_vars') < $count) {
            $return['error_type'] = 'conf';
            $return['max_input_vars'] = ini_get('max_input_vars');
            $return['needed_limit'] = $count + 100;
        }

        return $return;
    }

    private static function query($sqlQuery)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->query($sqlQuery);
    }

    public function cleanFilterByIdValue($attributes, $idValue)
    {
        $selectedFilters = [];
        if (is_array($attributes)) {
            foreach ($attributes as $attribute) {
                $attributeData = explode('_', $attribute);
                if ($attributeData[0] == $idValue) {
                    $selectedFilters[] = $attributeData[1];
                }
            }
        }

        return $selectedFilters;
    }

    public function rebuildLayeredStructure()
    {
        @set_time_limit(0);

        /* Set memory limit to 128M only if current is lower */
        $memoryLimit = Tools::getMemoryLimit();
        if ($memoryLimit != -1 && $memoryLimit < 128 * 1024 * 1024) {
            @ini_set('memory_limit', '128M');
        }

        /* Delete and re-create the layered categories table */
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'layered_category');

        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'layered_category` (
            `id_layered_category` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `id_category` INT(10) UNSIGNED NOT NULL,
            `id_value` INT(10) UNSIGNED NULL DEFAULT \'0\',
            `type` ENUM(\'category\',\'id_feature\',\'id_attribute_group\',\'quantity\',\'condition\',\'manufacturer\',\'weight\',\'price\') NOT NULL,
            `position` INT(10) UNSIGNED NOT NULL,
            `filter_type` int(10) UNSIGNED NOT NULL DEFAULT 0,
            `filter_show_limit` int(10) UNSIGNED NOT NULL DEFAULT 0,
            KEY `id_category_shop` (`id_category`, `id_shop`, `type`, id_value, `position`),
            KEY `id_category` (`id_category`,`type`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'layered_filter` (
            `id_layered_filter` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL,
            `filters` LONGTEXT NULL,
            `n_categories` INT(10) UNSIGNED NOT NULL,
            `date_add` DATETIME NOT NULL
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'layered_filter_block` (
            `hash` CHAR(32) NOT NULL DEFAULT "" PRIMARY KEY,
            `data` TEXT NULL
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );

        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'layered_filter_shop` (
            `id_layered_filter` INT(10) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`id_layered_filter`, `id_shop`),
            KEY `id_shop` (`id_shop`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    /**
     * @param array $productsIds
     * @param array $categoriesIds
     * @param bool $rebuildLayeredCategories
     */
    public function rebuildLayeredCache($productsIds = [], $categoriesIds = [], $rebuildLayeredCategories = true)
    {
        @set_time_limit(0);

        $filterData = ['categories' => []];

        /* Set memory limit to 128M only if current is lower */
        $memoryLimit = Tools::getMemoryLimit();
        if ($memoryLimit != -1 && $memoryLimit < 128 * 1024 * 1024) {
            @ini_set('memory_limit', '128M');
        }

        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $nCategories = [];
        $doneCategories = [];

        $alias = 'product_shop';
        $joinProduct = Shop::addSqlAssociation('product', 'p');
        $joinProductAttribute = Shop::addSqlAssociation('product_attribute', 'pa');

        $attributeGroups = self::query(
            'SELECT a.id_attribute, a.id_attribute_group
            FROM ' . _DB_PREFIX_ . 'attribute a
            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON (pac.id_attribute = a.id_attribute)
            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON (pa.id_product_attribute = pac.id_product_attribute)
            LEFT JOIN ' . _DB_PREFIX_ . 'product p ON (p.id_product = pa.id_product)
            ' . $joinProduct . $joinProductAttribute . '
            LEFT JOIN ' . _DB_PREFIX_ . 'category_product cp ON (cp.id_product = p.id_product)
            LEFT JOIN ' . _DB_PREFIX_ . 'category c ON (c.id_category = cp.id_category)
            WHERE c.active = 1' .
            (count($categoriesIds) ? ' AND cp.id_category IN (' . implode(',', array_map('intval', $categoriesIds)) . ')' : '') . '
            AND ' . $alias . '.active = 1 AND ' . $alias . '.`visibility` IN ("both", "catalog")
            ' . (count($productsIds) ? 'AND p.id_product IN (' . implode(',', array_map('intval', $productsIds)) . ')' : '')
        );

        $attributeGroupsById = [];
        while ($row = $db->nextRow($attributeGroups)) {
            $attributeGroupsById[(int) $row['id_attribute']] = (int) $row['id_attribute_group'];
        }

        $features = self::query(
            'SELECT fv.id_feature_value, fv.id_feature
            FROM ' . _DB_PREFIX_ . 'feature_value fv
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_product fp ON (fp.id_feature_value = fv.id_feature_value)
            LEFT JOIN ' . _DB_PREFIX_ . 'product p ON (p.id_product = fp.id_product)
            ' . $joinProduct . '
            LEFT JOIN ' . _DB_PREFIX_ . 'category_product cp ON (cp.id_product = p.id_product)
            LEFT JOIN ' . _DB_PREFIX_ . 'category c ON (c.id_category = cp.id_category)
            WHERE (fv.custom IS NULL OR fv.custom = 0) AND c.active = 1' . (count($categoriesIds) ? ' AND cp.id_category IN (' . implode(',', array_map('intval', $categoriesIds)) . ')' : '') . '
            AND ' . $alias . '.active = 1 AND ' . $alias . '.`visibility` IN ("both", "catalog") ' .
            (count($productsIds) ? 'AND p.id_product IN (' . implode(',', array_map('intval', $productsIds)) . ')' : '')
        );

        $featuresById = [];
        while ($row = $db->nextRow($features)) {
            $featuresById[(int) $row['id_feature_value']] = (int) $row['id_feature'];
        }

        $result = self::query(
            'SELECT p.id_product,
            GROUP_CONCAT(DISTINCT fv.id_feature_value) features,
            GROUP_CONCAT(DISTINCT cp.id_category) categories,
            GROUP_CONCAT(DISTINCT pac.id_attribute) attributes
            FROM ' . _DB_PREFIX_ . 'product p
            LEFT JOIN ' . _DB_PREFIX_ . 'category_product cp ON (cp.id_product = p.id_product)
            LEFT JOIN ' . _DB_PREFIX_ . 'category c ON (c.id_category = cp.id_category)
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_product fp ON (fp.id_product = p.id_product)
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value fv ON (fv.id_feature_value = fp.id_feature_value)
            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON (pa.id_product = p.id_product)
            ' . $joinProduct . $joinProductAttribute . '
            LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON (pac.id_product_attribute = pa.id_product_attribute)
            WHERE c.active = 1' . (count($categoriesIds) ? ' AND cp.id_category IN (' . implode(',', array_map('intval', $categoriesIds)) . ')' : '') . '
            AND ' . $alias . '.active = 1 AND ' . $alias . '.`visibility` IN ("both", "catalog")
            ' . (count($productsIds) ? 'AND p.id_product IN (' . implode(',', array_map('intval', $productsIds)) . ')' : '') .
            ' AND (fv.custom IS NULL OR fv.custom = 0)
            GROUP BY p.id_product'
        );

        $shopList = Shop::getShops(false, null, true);

        $toInsert = false;
        while ($product = $db->nextRow($result)) {
            $a = $c = $f = [];
            if (!empty($product['attributes'])) {
                $a = array_flip(explode(',', $product['attributes']));
            }

            if (!empty($product['categories'])) {
                $c = array_flip(explode(',', $product['categories']));
            }

            if (!empty($product['features'])) {
                $f = array_flip(explode(',', $product['features']));
            }

            $filterData['shop_list'] = $shopList;

            foreach ($c as $idCategory => $category) {
                if (!in_array($idCategory, $filterData['categories'])) {
                    $filterData['categories'][] = $idCategory;
                }

                if (!isset($nCategories[(int) $idCategory])) {
                    $nCategories[(int) $idCategory] = 1;
                }
                if (!isset($doneCategories[(int) $idCategory]['cat'])) {
                    $filterData['layered_selection_subcategories'] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                    $doneCategories[(int) $idCategory]['cat'] = true;
                    $toInsert = true;
                }
                if (is_array($attributeGroupsById) && count($attributeGroupsById) > 0) {
                    foreach ($a as $kAttribute => $attribute) {
                        if (!isset($doneCategories[(int) $idCategory]['a' . (int) $attributeGroupsById[(int) $kAttribute]])) {
                            $filterData['layered_selection_ag_' . (int) $attributeGroupsById[(int) $kAttribute]] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                            $doneCategories[(int) $idCategory]['a' . (int) $attributeGroupsById[(int) $kAttribute]] = true;
                            $toInsert = true;
                        }
                    }
                }
                if (is_array($attributeGroupsById) && count($attributeGroupsById) > 0) {
                    foreach ($f as $kFeature => $feature) {
                        if (!isset($doneCategories[(int) $idCategory]['f' . (int) $featuresById[(int) $kFeature]])) {
                            $filterData['layered_selection_feat_' . (int) $featuresById[(int) $kFeature]] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                            $doneCategories[(int) $idCategory]['f' . (int) $featuresById[(int) $kFeature]] = true;
                            $toInsert = true;
                        }
                    }
                }

                if (!isset($doneCategories[(int) $idCategory]['q'])) {
                    $filterData['layered_selection_stock'] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                    $doneCategories[(int) $idCategory]['q'] = true;
                    $toInsert = true;
                }

                if (!isset($doneCategories[(int) $idCategory]['m'])) {
                    $filterData['layered_selection_manufacturer'] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                    $doneCategories[(int) $idCategory]['m'] = true;
                    $toInsert = true;
                }

                if (!isset($doneCategories[(int) $idCategory]['c'])) {
                    $filterData['layered_selection_condition'] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                    $doneCategories[(int) $idCategory]['c'] = true;
                    $toInsert = true;
                }

                if (!isset($doneCategories[(int) $idCategory]['w'])) {
                    $filterData['layered_selection_weight_slider'] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                    $doneCategories[(int) $idCategory]['w'] = true;
                    $toInsert = true;
                }

                if (!isset($doneCategories[(int) $idCategory]['p'])) {
                    $filterData['layered_selection_price_slider'] = ['filter_type' => Converter::WIDGET_TYPE_CHECKBOX, 'filter_show_limit' => 0];
                    $doneCategories[(int) $idCategory]['p'] = true;
                    $toInsert = true;
                }
            }
        }

        if ($toInsert) {
            Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_filter(name, filters, n_categories, date_add)
VALUES (\'' . sprintf($this->trans('My template %s', [], 'Modules.Facetedsearch.Admin'), date('Y-m-d')) . '\', \'' . pSQL(serialize($filterData)) . '\', ' . count($filterData['categories']) . ', NOW())');

            $last_id = Db::getInstance()->Insert_ID();
            Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_filter_shop WHERE `id_layered_filter` = ' . $last_id);
            foreach ($shopList as $idShop) {
                Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_filter_shop (`id_layered_filter`, `id_shop`)
VALUES(' . $last_id . ', ' . (int) $idShop . ')');
            }

            if ($rebuildLayeredCategories) {
                $this->buildLayeredCategories();
            }
        }
    }

    public function buildLayeredCategories()
    {
        // Get all filter template
        $res = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'layered_filter ORDER BY date_add DESC');
        $categories = [];
        // Remove all from layered_category
        Db::getInstance()->execute('TRUNCATE ' . _DB_PREFIX_ . 'layered_category');

        if (!count($res)) { // No filters templates defined, nothing else to do
            return true;
        }

        $sqlInsertPrefix = 'INSERT INTO ' . _DB_PREFIX_ . 'layered_category (id_category, id_shop, id_value, type, position, filter_show_limit, filter_type) VALUES ';
        $sqlInsert = '';
        $nbSqlValuesToInsert = 0;

        foreach ($res as $filterTemplate) {
            $data = Tools::unSerialize($filterTemplate['filters']);
            foreach ($data['shop_list'] as $idShop) {
                if (!isset($categories[$idShop])) {
                    $categories[$idShop] = [];
                }

                foreach ($data['categories'] as $idCategory) {
                    $n = 0;
                    if (in_array($idCategory, $categories[$idShop])) {
                        continue;
                    }
                    // Last definition, erase previous categories defined

                    $categories[$idShop][] = $idCategory;

                    foreach ($data as $key => $value) {
                        if (substr($key, 0, 17) == 'layered_selection') {
                            $type = $value['filter_type'];
                            $limit = $value['filter_show_limit'];
                            ++$n;

                            if ($key == 'layered_selection_stock') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', NULL,\'quantity\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            } elseif ($key == 'layered_selection_subcategories') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', NULL,\'category\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            } elseif ($key == 'layered_selection_condition') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', NULL,\'condition\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            } elseif ($key == 'layered_selection_weight_slider') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', NULL,\'weight\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            } elseif ($key == 'layered_selection_price_slider') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', NULL,\'price\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            } elseif ($key == 'layered_selection_manufacturer') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', NULL,\'manufacturer\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            } elseif (substr($key, 0, 21) == 'layered_selection_ag_') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', ' . (int) str_replace('layered_selection_ag_', '', $key) . ',
\'id_attribute_group\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            } elseif (substr($key, 0, 23) == 'layered_selection_feat_') {
                                $sqlInsert .= '(' . (int) $idCategory . ', ' . (int) $idShop . ', ' . (int) str_replace('layered_selection_feat_', '', $key) . ',
\'id_feature\',' . (int) $n . ', ' . (int) $limit . ', ' . (int) $type . '),';
                            }

                            ++$nbSqlValuesToInsert;
                            if ($nbSqlValuesToInsert >= 100) {
                                Db::getInstance()->execute($sqlInsertPrefix . rtrim($sqlInsert, ','));
                                $sqlInsert = '';
                                $nbSqlValuesToInsert = 0;
                            }
                        }
                    }
                }
            }
        }

        if ($nbSqlValuesToInsert) {
            Db::getInstance()->execute($sqlInsertPrefix . rtrim($sqlInsert, ','));
        }
    }

    /**
     * Render template
     *
     * @param string $template
     * @param array  $params
     *
     * @return string
     */
    public function render($template, array $params = [])
    {
        $this->context->smarty->assign($params);

        return $this->display(__FILE__, $template);
    }

    /**
     * Is ajax request
     *
     * @return boolean
     */
    public function isAjax()
    {
        return $this->ajax;
    }

    /**
     * Check for link rewirte
     *
     * @param array $params
     */
    private function checkLinksRewrite($params)
    {
        foreach (Language::getLanguages(false) as $language) {
            $idLang = $language['id_lang'];
            $urlNameLang = Tools::getValue('url_name_' . $idLang);
            if ($urlNameLang && Tools::link_rewrite($urlNameLang) != strtolower($urlNameLang)) {
                $params['errors'][] = Tools::displayError(
                    $this->trans(
                        '"%s" is not a valid url',
                        [$urlNameLang],
                        'Modules.Facetedsearch.Admin'
                    )
                );
            }
        }
    }
}
