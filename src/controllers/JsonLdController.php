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

namespace nystudio107\seomatic\controllers;

use nystudio107\seomatic\helpers\Schema as SchemaHelper;

use craft\web\Controller;

/**
 * @author    nystudio107
 * @package   Seomatic
 * @since     1.0.0
 */
class JsonLdController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = [
        'get-type',
        'get-decomposed-type',
        'get-type-array',
        'get-type-menu',
        'get-single-type-menu',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Get the fully composed schema type
     *
     * @param string $schemaType
     *
     * @return \yii\web\Response
     */
    public function actionGetType($schemaType)
    {
        return $this->asJson(SchemaHelper::getSchemaType($schemaType));
    }

    /**
     * Get the decomposed schema type
     *
     * @param string $schemaType
     *
     * @return \yii\web\Response
     */
    public function actionGetDecomposedType($schemaType)
    {
        return $this->asJson(SchemaHelper::getDecomposedSchemaType($schemaType));
    }

    /**
     * Get an array of schema types
     *
     * @param string $path
     *
     * @return \yii\web\Response
     */
    public function actionGetTypeArray($path)
    {
        return $this->asJson(SchemaHelper::getSchemaArray($path));
    }

    /**
     * Get a flattened menu of schema types as an array
     *
     * @param string $path
     *
     * @return \yii\web\Response
     */
    public function actionGetTypeMenu($path)
    {
        return $this->asJson(SchemaHelper::getSchemaMenu($path));
    }

    /**
     * Return a single menu of schemas starting at $path
     *
     * @param string $path
     *
     * @return \yii\web\Response
     */
    public function actionGetSingleTypeMenu($path)
    {
        return $this->asJson(SchemaHelper::getSingleSchemaMenu($path));
    }
}
