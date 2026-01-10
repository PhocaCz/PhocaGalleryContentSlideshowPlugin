<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.phocagalleryslideshow
 *
 * Original extension:
 * - Phoca Gallery Slideshow Plugin
 * - Author: Jan Pavelka (www.phoca.cz)
 *
 * Community patch (Joomla 6 / PHP 8.x compatibility):
 * - Replaced missing legacy fadeslideshow dependency with bundled JS (media/plg_content_phocagalleryslideshow/js/fadeslideshow.js)
 * - Deferred slideshow init to DOMContentLoaded to ensure wrapper exists
 * - Added responsive wrapper behaviour (shrinks on small screens while keeping aspect ratio)
 * - Added hover pause, touch swipe, lazy loading + optional mouse drag swipe support (in fadeslideshow.js)
 *
 * Patch maintainer: Andy Marchand (Feuerwehr Worb / community)
 * Patch version: 4.4.0-j6fix.1 (2026-01-06)
 *
 * @license     GNU/GPL
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
//use Joomla\CMS\Filesystem\File;

class plgContentPhocaGallerySlideshow extends CMSPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage();
    }

    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        // Do not run in indexer
        if ($context === 'com_finder.indexer') {
            return true;
        }

        if (!isset($article->text) || $article->text === '') {
            return true;
        }

        // Include Phoca Gallery
        if (!ComponentHelper::isEnabled('com_phocagallery', true)) {
            // avoid echo in plugins; just keep tag as-is
            return true;
        }

        if (!class_exists('PhocaGalleryLoader')) {
            require_once JPATH_ADMINISTRATOR . '/components/com_phocagallery/libraries/loader.php';
        }

        // Phoca imports
        phocagalleryimport('phocagallery.path.path');
        phocagalleryimport('phocagallery.path.route');
        phocagalleryimport('phocagallery.library.library');
        phocagalleryimport('phocagallery.text.text');
        phocagalleryimport('phocagallery.access.access');
        phocagalleryimport('phocagallery.file.file');
        phocagalleryimport('phocagallery.file.filethumbnail');
        phocagalleryimport('phocagallery.image.image');
        phocagalleryimport('phocagallery.image.imagefront');
        phocagalleryimport('phocagallery.render.renderfront');
        phocagalleryimport('phocagallery.render.renderadmin');
        phocagalleryimport('phocagallery.render.renderdetailwindow');
        phocagalleryimport('phocagallery.ordering.ordering');
        phocagalleryimport('phocagallery.picasa.picasa');
        phocagalleryimport('phocagallery.html.category');

        $db       = Factory::getDbo();
        $document = Factory::getDocument();
        $app      = Factory::getApplication();

        $view   = $app->input->getCmd('view');
        $layout = $app->input->getCmd('layout');

        $paramsC = ComponentHelper::getParams('com_phocagallery');

        // Start Plugin
        $regex_one = '/({pgslideshow\s*)(.*?)(})/si';
        $regex_all = '/{pgslideshow\s*.*?}/si';

        $matches = [];
        $count_matches = preg_match_all($regex_all, $article->text, $matches, PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER);

        if (!$count_matches) {
            return true;
        }

        for ($j = 0; $j < $count_matches; $j++) {

            // Plugin variables
            $id     = 0;
            $width  = 640;
            $height = 480;
            $delay  = 3000;
            $image  = 'L';

            $tmpl = [];
            $tmpl['pgslink']        = 0;
            $tmpl['imageordering']  = (int) $paramsC->get('image_ordering', 9);

            $desc   = 'peekaboo';
            $random = 0;
            $pause  = 2500;

            // Get plugin parameters
            $phocagallery = $matches[0][$j][0];
            $phocagallery_parts = [];
            preg_match($regex_one, $phocagallery, $phocagallery_parts);

            $parts = isset($phocagallery_parts[2]) ? explode("|", $phocagallery_parts[2]) : [];
            $values_replace = ["/^'/", "/'$/", "/^&#39;/", "/&#39;$/", "/<br \/>/"];

            foreach ($parts as $value) {
                $values = explode("=", $value, 2);

                foreach ($values_replace as $values2) {
                    $values = preg_replace($values2, '', $values);
                }

                if (!isset($values[0], $values[1])) {
                    continue;
                }

                if ($values[0] === 'id') {
                    $id = (int) $values[1];
                } elseif ($values[0] === 'height') {
                    $height = (int) $values[1];
                } elseif ($values[0] === 'width') {
                    $width = (int) $values[1];
                } elseif ($values[0] === 'delay') {
                    $delay = (int) $values[1];
                } elseif ($values[0] === 'image') {
                    $image = (string) $values[1];
                } elseif ($values[0] === 'desc') {
                    $desc = (string) $values[1];
                } elseif ($values[0] === 'random') {
                    $random = (int) $values[1];
                } elseif ($values[0] === 'pause') {
                    $pause = (int) $values[1];
                } elseif ($values[0] === 'pgslink') {
                    $tmpl['pgslink'] = (int) $values[1];
                } elseif ($values[0] === 'imageordering') {
                    $tmpl['imageordering'] = (int) $values[1];
                }
            }

            if ($id <= 0) {
                continue;
            }

            // Load language for Phoca Gallery
            $lang = Factory::getLanguage();
            $lang->load('com_phocagallery');

            $orderingString = PhocaGalleryOrdering::getOrderingString($tmpl['imageordering']);
            $imageOrdering  = $orderingString['output'];

            $c = time() . mt_rand();

            $query = ' SELECT a.filename, cc.id as catid, cc.alias as catalias, a.extid, a.exts, a.extm, a.extl, a.exto, a.description,'
                . ' CASE WHEN CHAR_LENGTH(cc.alias) THEN CONCAT_WS(\':\', cc.id, cc.alias) ELSE cc.id END as catslug'
                . ' FROM #__phocagallery_categories AS cc'
                . ' LEFT JOIN #__phocagallery AS a ON a.catid = cc.id'
                . ' WHERE cc.published = 1'
                . ' AND a.published = 1'
                . ' AND cc.approved = 1'
                . ' AND a.approved = 1'
                . ' AND a.catid = ' . (int) $id
                . $imageOrdering;

            $db->setQuery($query);
            $images = $db->loadObjectList();

            // START OUTPUT
            $jsSlideshowData = [];
            $jsSlideshowData['files'] = '';

            $countImg = 0;
            $endComma = ',';
            $output   = '';

            if (!empty($images)) {

                $countFilename = count($images);

                foreach ($images as $value) {
                    $countImg++;
                    if ($countImg === $countFilename) {
                        $endComma = '';
                    }

                    $description = '';
                    if ($desc !== 'none') {
                        if (($variable ?? null) !== null) {
                            $description = PhocaGalleryText::strTrimAll(addslashes($value->description));
                        }
                    }

                    switch ($image) {
                        case 'S':
                            $imageName = PhocaGalleryFileThumbnail::getThumbnailName($value->filename, 'small');
                            $imageName->ext = $value->exts;
                            $sizeString = 's';
                            break;

                        case 'M':
                            $imageName = PhocaGalleryFileThumbnail::getThumbnailName($value->filename, 'medium');
                            $imageName->ext = $value->extm;
                            $sizeString = 'm';
                            break;

                        case 'O':
                            $imageName = new stdClass();
                            $imageName->rel = PhocaGalleryFile::getFileOriginal($value->filename, 1);
                            $imageName->abs = PhocaGalleryFile::getFileOriginal($value->filename, 0);
                            $imageName->ext = $value->exto;
                            $sizeString = 'l';
                            break;

                        case 'L':
                        default:
                            $imageName = PhocaGalleryFileThumbnail::getThumbnailName($value->filename, 'large');
                            $imageName->ext = $value->extl;
                            $sizeString = 'l';
                            break;
                    }

                    if (isset($value->extl) && $value->extl !== '') {
                        $jsSlideshowData['files'] .= '["' . $imageName->ext . '", "", "", "' . $description . '"]' . $endComma . "\n";
                    } else {
                        $imgLink = Uri::base(true) . '/' . $imageName->rel;

                        if (is_file($imageName->abs)) {
                            $jsSlideshowData['files'] .= '["' . $imgLink . '", "", "", "' . $description . '"]' . $endComma . "\n";
                        } else {
                            $fileThumbnail = Uri::base(true) . '/' . 'components/com_phocagallery/assets/images/phoca_thumb_' . $sizeString . '_no_image.png';
                            $jsSlideshowData['files'] .= '["' . $fileThumbnail . '", "", "", ""]' . $endComma . "\n";
                        }
                    }
                }

                $interval = ($pause > 0) ? $pause : $delay;   // Pause between slides
                $fadeMs   = 1000;                             // Transition period (ms)

                $script  = "/***********************************************\n";
                $script .= "* Phoca Gallery Content Slideshow Plugin\n";
                $script .= "* Community patch – Joomla 6 / PHP 8.x compatibility\n";
                $script .= "* Slideshow init script (uses bundled fadeslideshow.js)\n";
                $script .= "* Patch version: 4.4.0-j6fix.1\n";
                $script .= "***********************************************/\n";

                // IMPORTANT: defer init until DOM exists (wrapper div is in article body)
                $script .= "(function(){\n";
                $script .= "  function eikoInitPhocaSlideshow{$c}(){\n";
                $script .= "    try {\n";
                $script .= "      window.phocagalleryplugin{$c} = new fadeSlideShow({\n";
                $script .= "        wrapperid: \"phocaGallerySlideshowP{$c}\",\n";
                $script .= "        dimensions: ['100%', '100%'],\n";
                $script .= "        imagearray: [{$jsSlideshowData['files']}],\n";
                $script .= "        displaymode: {type:'auto', pause: {$interval}, cycles:0,\n";
                $script .= "          wraparound:false, randomize: {$random}},\n";
                $script .= "        persist: false,\n";
                $script .= "        fadeduration: {$fadeMs},\n";
                $script .= "        descreveal: \"{$desc}\",\n";
                $script .= "        togglerid: \"\"\n";
                $script .= "      });\n";
                $script .= "    } catch(e) {}\n";
                $script .= "  }\n";
                $script .= "  if (document.readyState === 'loading') {\n";
                $script .= "    document.addEventListener('DOMContentLoaded', eikoInitPhocaSlideshow{$c});\n";
                $script .= "  } else {\n";
                $script .= "    eikoInitPhocaSlideshow{$c}();\n";
                $script .= "  }\n";
                $script .= "})();\n";

                $siteLink = '';
                if (isset($value->catid)) {
                    if ((int) $tmpl['pgslink'] === 2) {
                        $siteLink = Route::_(PhocaGalleryRoute::getCategoriesRoute());
                    } elseif ((int) $tmpl['pgslink'] === 1) {
                        $siteLink = Route::_(PhocaGalleryRoute::getCategoryRoute($value->catid, $value->catalias));
                    }
                }

                // Add JS in these views
                if ($view === 'article' || $view === 'featured' || $view === 'item' || ($view === 'category' && $layout === 'blog')) {

                    // Responsive CSS for the slideshow container (injected once)
                    static $eikoCssAdded = false;
                    if (!$eikoCssAdded) {
                        $eikoCssAdded = true;
                        $document->addStyleDeclaration('
.phocagalleryslideshow{max-width:100%;}
.phocagalleryslideshow a{display:block;width:100%;height:100%;}
.phocagalleryslideshow img{max-width:100%;height:auto;}
');
                    }

                    // Only needed if other site code requires it; harmless if present
                    HTMLHelper::_('jquery.framework');

                    // IMPORTANT: our replacement JS (because com_phocagallery no longer ships fadeslideshow.js)
                    HTMLHelper::_('script', 'media/plg_content_phocagalleryslideshow/js/fadeslideshow.js', ['version' => 'auto']);

                    $document->addScriptDeclaration($script);
                }

                // Responsive wrapper: shrinks on mobile, keeps aspect ratio from width/height
                $output .= '<div class="phocagalleryslideshow" style="width:min(' . (int) $width . 'px,100%);aspect-ratio:' . (int) $width . ' / ' . (int) $height . ';height:auto;padding:0;margin:auto;">' . "\n";
                if ($siteLink !== '') {
                    $output .= '<a href="' . $siteLink . '"><div id="phocaGallerySlideshowP' . $c . '" style="width:100%;height:100%;padding:0;margin:0;"></div></a>' . "\n";
                } else {
                    $output .= '<div id="phocaGallerySlideshowP' . $c . '" style="width:100%;height:100%;padding:0;margin:0;"></div>' . "\n";
                }
                $output .= "</div>";

            } else {
                $output .= Text::_('PLG_CONTENT_PHOCAGALLERYSLIDESHOW_THERE_IS_NO_IMAGE_OR_CATEGORY_IS_UNPUBLISHED_OR_NOT_AUTHORIZED');
            }

            // Replace the first match only (as in original plugin)
            $article->text = preg_replace($regex_all, $output, $article->text, 1);
        }

        return true;
    }
}
