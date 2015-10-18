<?php
/**
 * Universal Viewer
 *
 * This plugin integrates the Universal Viewer, the open sourced viewer taht is
 * the successor of the Wellcome Viewer of Digirati, into Omeka.
 *
 * @copyright Daniel Berthereau, 2015
 * @license https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
 * @license https://github.com/UniversalViewer/universalviewer/blob/master/LICENSE.txt (viewer)
 *  */

/**
 * The Universal Viewer plugin.
 * @package Omeka\Plugins\UniversalViewer
 */
class UniversalViewerPlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array Hooks for the plugin.
     */
    protected $_hooks = array(
        'install',
        'uninstall',
        'initialize',
        'config_form',
        'config',
        'define_routes',
        'admin_items_batch_edit_form',
        'items_batch_edit_custom',
        'public_collections_show',
        'public_items_show',
    );

    /**
     * @var array Filters for the plugin.
     */
    protected $_filters = array(
        // It's a checkbox, so no error can be done.
        // 'items_batch_edit_error',
    );

    /**
     * @var array Options and their default values.
     */
    protected $_options = array(
        'universalviewer_append_collections_show' => true,
        'universalviewer_append_items_show' => true,
        'universalviewer_max_dynamic_size' => 10000000,
        'universalviewer_licence' => 'http://www.example.org/license.html',
        'universalviewer_attribution' => 'Provided by Example Organization',
        'universalviewer_class' => '',
        'universalviewer_width' => '95%',
        'universalviewer_height' => '600px',
        'universalviewer_locale' => 'en-GB:English (GB),fr-FR:French',
    );

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        $this->_installOptions();
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $this->_uninstallOptions();
    }

    /**
     * Initialize the plugin.
     */
    public function hookInitialize()
    {
        add_translation_source(dirname(__FILE__) . '/languages');
        add_shortcode('uv', array($this, 'shortcodeUniversalViewer'));
    }

    /**
     * Shows plugin configuration page.
     *
     * @return void
     */
    public function hookConfigForm($args)
    {
        $view = get_view();
        echo $view->partial(
            'plugins/universal-viewer-config-form.php'
        );
    }

    /**
     * Processes the configuration form.
     *
     * @param array Options set in the config form.
     * @return void
     */
    public function hookConfig($args)
    {
        $post = $args['post'];
        foreach ($this->_options as $optionKey => $optionValue) {
            if (isset($post[$optionKey])) {
                set_option($optionKey, $post[$optionKey]);
            }
        }
    }

    /**
     * Defines public routes.
     *
     * @return void
     */
    public function hookDefineRoutes($args)
    {
        if (is_admin_theme()) {
            return;
        }

        $args['router']->addConfig(new Zend_Config_Ini(dirname(__FILE__) . '/routes.ini', 'routes'));
    }

    /**
     * Add a partial batch edit form.
     *
     * @return void
     */
    public function hookAdminItemsBatchEditForm($args)
    {
        $view = get_view();
        echo $view->partial(
            'forms/universal-viewer-batch-edit.php'
        );
    }

    /**
     * Process the partial batch edit form.
     *
     * @return void
     */
    public function hookItemsBatchEditCustom($args)
    {
        $item = $args['item'];
        $orderByFilename = $args['custom']['universalviewer']['orderByFilename'];
        $mixImages = $args['custom']['universalviewer']['mixImages'];

        if ($orderByFilename) {
            $this->_sortFiles($item, (boolean) $mixImages);
        }
    }

    /**
     * Sort all files of an item by name and eventually sort images first.
     *
     * @param Item $item
     * @param boolean $mixImages
     * @return void
     */
    protected function _sortFiles($item, $mixImages = false)
    {
        if ($item->fileCount() == 0) {
            return;
        }

        $list = $item->Files;
        // Make a sort by name before sort by type.
        usort($list, function($fileA, $fileB) {
            return strcmp($fileA->original_filename, $fileB->original_filename);
        });
        // The sort by type doesn't remix all filenames.
        if (!$mixImages) {
            $images = array();
            $nonImages = array();
            foreach ($list as $file) {
                // Image.
                if (strpos($file->mime_type, 'image/') === 0) {
                    $images[] = $file;
                }
                // Non image.
                else {
                    $nonImages[] = $file;
                }
            }
            $list = array_merge($images, $nonImages);
        }

        // To avoid issues with unique index when updating (order should be
        // unique for each file of an item), all orders are reset to null before
        // true process.
        $db = $this->_db;
        $bind = array(
            $item->id,
        );
        $sql = "
            UPDATE `$db->File` files
            SET files.order = NULL
            WHERE files.item_id = ?
        ";
        $db->query($sql, $bind);

        // To avoid multiple updates, a single query is used.
        foreach ($list as &$file) {
            $file = $file->id;
        }
        // The array is made unique, because a file can be repeated.
        $list = implode(',', array_unique($list));
        $sql = "
            UPDATE `$db->File` files
            SET files.order = FIND_IN_SET(files.id, '$list')
            WHERE files.id in ($list)
        ";
        $db->query($sql);
    }

    /**
     * Hook to display viewer.
     *
     * @param array $args
     *
     * @return void
     */
    public function hookPublicCollectionsShow($args)
    {
        if (!get_option('universalviewer_append_collections_show')) {
            return;
        }
        if (!isset($args['view'])) {
            $args['view'] = get_view();
        }
        echo $args['view']->universalViewer($args);
    }

    /**
     * Hook to display viewer.
     *
     * @param array $args
     *
     * @return void
     */
    public function hookPublicItemsShow($args)
    {
        if (!get_option('universalviewer_append_items_show')) {
            return;
        }
        if (!isset($args['view'])) {
            $args['view'] = get_view();
        }
        echo $args['view']->universalViewer($args);
    }

    /**
     * Shortcode to display viewer.
     *
     * @param array $args
     * @param Omeka_View $view
     * @return string
     */
    public static function shortcodeUniversalViewer($args, $view)
    {
        $args['view'] = $view;
        return $view->universalViewer($args);
    }
}
