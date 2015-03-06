<?php
/* @package Joomla
 * @copyright Copyright (C) Open Source Matters. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * @extension Phoca Extension
 * @copyright Copyright (C) Jan Pavelka www.phoca.cz
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
 
defined('_JEXEC') or die('Restricted access');
if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
jimport('joomla.plugin.plugin');
if (!JComponentHelper::isEnabled('com_phocagallery', true)) {
	return JError::raiseError(JText::_('PLG_CONTENT_PHOCAGALLERYSLIDESHOW_PHOCA_GALLERY_ERROR'), JText::_('PLG_CONTENT_PHOCAGALLERYSLIDESHOW_PHOCA_GALLERY_IS_NOT_INSTALLED_ON_YOUR_SYSTEM'));
}

if (! class_exists('PhocaGalleryLoader')) {
    require_once( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_phocagallery'.DS.'libraries'.DS.'loader.php');
}

phocagalleryimport('phocagallery.path.path');
phocagalleryimport('phocagallery.path.route');
phocagalleryimport('phocagallery.file.file');
phocagalleryimport('phocagallery.text.text');
phocagalleryimport('phocagallery.file.filethumbnail');
phocagalleryimport('phocagallery.ordering.ordering');

class plgContentPhocaGallerySlideshow extends JPlugin
{	
	public function __construct(& $subject, $config) {
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}

	public function onContentPrepare($context, &$article, &$params, $page = 0) {
		
		
		if ($context == 'com_finder.indexer') {
			return true;
		}
		
		$db 		= JFactory::getDBO();
		$document	= JFactory::getDocument();
		$path 		= PhocaGalleryPath::getPath();
		//$menu 		= &JSite::getMenu();
		$app 		= JFactory::getApplication('site');
		$view		= JRequest::getCmd('view');
		$layout		= JRequest::getCmd('layout');
		
		
		$component			=	'com_phocagallery';
		$paramsC			= JComponentHelper::getParams($component) ;

		// Start Plugin
		$regex_one		= '/({pgslideshow\s*)(.*?)(})/si';
		$regex_all		= '/{pgslideshow\s*.*?}/si';
		$matches 		= array();
		$count_matches	= preg_match_all($regex_all,$article->text,$matches,PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER);
		$customCSS		= '';
		$customCSS2		= '';
		
		for($j = 0; $j < $count_matches; $j++) {
			// Plugin variables
			$id						= 0;
			$width 					= 640;
			$height					= 480;
			$delay					= 3000;
			$image					= 'L';
			$tmpl['pgslink']		= 0;
			$tmpl['imageordering']	= $paramsC->get( 'image_ordering', 9);
			$desc					= 'peekaboo';
			$random					= 0;
			$pause					= 2500;
			
			// Get plugin parameters
			$phocagallery	= $matches[0][$j][0];
			preg_match($regex_one,$phocagallery,$phocagallery_parts);
			$parts			= explode("|", $phocagallery_parts[2]);
			$values_replace = array ("/^'/", "/'$/", "/^&#39;/", "/&#39;$/", "/<br \/>/");

			foreach($parts as $key => $value) {
				
				$values = explode("=", $value, 2);
				foreach ($values_replace as $key2 => $values2) {
					$values = preg_replace($values2, '', $values);
				}
				
				// Get plugin parameters from article
				if($values[0]=='id')					{$id					= $values[1];}
				else if($values[0]=='height')			{$height				= $values[1];}
				else if($values[0]=='width')			{$width					= $values[1];}
				else if($values[0]=='delay')			{$delay					= $values[1];}
				else if($values[0]=='image')			{$image					= $values[1];}
				else if($values[0]=='desc')				{$desc					= $values[1];}
				else if($values[0]=='random')			{$random					= $values[1];}
				else if($values[0]=='pause')			{$pause					= $values[1];}
				else if($values[0]=='pgslink')			{$tmpl['pgslink']		= $values[1];}
				else if($values[0]=='imageordering')	{$tmpl['imageordering']	= $values[1];}
			}
			
			if ($id > 0) {
			
				$orderingString=PhocaGalleryOrdering::getOrderingString($tmpl['imageordering']);
				$imageOrdering =$orderingString['output'];
				
			
				//$c = time() * rand(1,10);
				//$c = time() * mt_rand(1,1000); 
				$c = time() . mt_rand();				
				$query     = ' SELECT a.filename, cc.id as catid, cc.alias as catalias, a.extid, a.exts, a.extm, a.extl, a.exto, a.description,'
						   . ' CASE WHEN CHAR_LENGTH(cc.alias) THEN CONCAT_WS(\':\', cc.id, cc.alias) ELSE cc.id END as catslug'
						   . ' FROM #__phocagallery_categories AS cc'
						   . ' LEFT JOIN #__phocagallery AS a ON a.catid = cc.id'
						   . ' WHERE cc.published = 1'
						   . ' AND a.published = 1'
						   . ' AND cc.approved = 1'
						   . ' AND a.approved = 1'
						   . ' AND a.catid = ' . (int)$id
						   . $imageOrdering;
				$db->setQuery($query);
				$images = $db->loadObjectList();
			
				
// START OUTPUT

$jsSlideshowData['files'] = '';
$countImg 	= 0;
$endComma	= ',';
$output 	= '';
if (!empty($images)) {
	
	$countFilename = count($images);
	foreach ($images as $key => $value) {

		$countImg++;
		if ($countImg == $countFilename) {
			$endComma = '';
		}
		if ($desc != 'none') {
			$description = PhocaGalleryText::strTrimAll(addslashes( $value->description ));
		} else {
			$description = "";
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
				$imageName		= new stdClass();
				$imageName->rel = PhocaGalleryFile::getFileOriginal($value->filename , 1);
				$imageName->abs = PhocaGalleryFile::getFileOriginal($value->filename , 0);
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
		

		if (isset($value->extl) && $value->extl != '') {
			$jsSlideshowData['files'] .= '["'. $imageName->ext .'", "", "", "'.$description.'"]'.$endComma."\n"; 
		} else {
			$imgLink		= JURI::base(true) . '/' . $imageName->rel;
			
			if (JFile::exists($imageName->abs)) {
				$jsSlideshowData['files'] .= '["'. $imgLink .'", "", "", "'.$description.'"]'.$endComma."\n"; ; 
			} else {
				$fileThumbnail = JURI::base(true).'/' . "components/com_phocagallery/assets/images/phoca_thumb_".
				$sizeString . "_no_image.png";
				$jsSlideshowData['files'] .= '["'.$fileThumbnail.'", "", "", ""]'.$endComma."\n";
			}
		}
	}
	
	
	//$script  = '<script type="text/javascript">' . "\n";
	$script  = '/***********************************************' . "\n";
	$script  .= '* Ultimate Fade In Slideshow v2.0- (c) Dynamic Drive DHTML code library (www.dynamicdrive.com)' . "\n";
	$script  .= '* This notice MUST stay intact for legal use' . "\n";
	$script  .= '* Visit Dynamic Drive at http://www.dynamicdrive.com/ for this script and 100s more' . "\n";
	$script  .= '***********************************************/' . "\n";
	$script  .= 'var phocagalleryplugin'.$c.' = new fadeSlideShow({' . "\n";
	$script  .= ' wrapperid: "phocaGallerySlideshowP'.$c.'",' . "\n";
	$script  .= ' dimensions: ['.$width.', '.$height.'],' . "\n";
	$script  .= ' imagearray: ['.$jsSlideshowData['files'].'],' . "\n";
	$script  .= ' displaymode: {type:\'auto\', pause: '.$pause.', cycles:0,' . "\n";
	$script  .= ' wraparound:false, randomize: '.$random.'},' . "\n";
	$script  .= ' persist: false,' . "\n";
	$script  .= ' fadeduration: '.$delay.',' . "\n";
	$script  .= ' descreveal: "'.$desc.'",' . "\n";
	$script  .= ' togglerid: ""' . "\n";
	$script  .= '})' . "\n";
//	$script  .= '</script>' . "\n";
	
	
	$siteLink = '';
	if (isset($value->catid)) {
		if ((int)$tmpl['pgslink'] == 2) {
			// Different Link - to all categories
			$siteLink = JRoute::_(PhocaGalleryRoute::getCategoriesRoute());
		} else if ((int)$tmpl['pgslink'] == 1) {
			// Different Link - to all category
			$siteLink = JRoute::_(PhocaGalleryRoute::getCategoryRoute($value->catid, $value->catalias));
		}
	}
	
	// Don't add js in category view
	//if ($view == 'article' || $view == 'featured' || ($view == 'category' && $layout == 'blog')) {
	if ($view == 'article' || $view == 'featured' || $view == 'item' ||($view == 'category' && $layout == 'blog')) {
		//$document->addScript(JURI::base(true).'/components/com_phocagallery/assets/jquery/jquery-1.6.4.min.js');
		JHtml::_('jquery.framework', false);
		$document->addScript(JURI::base(true).'/components/com_phocagallery/assets/fadeslideshow/fadeslideshow.js');
		$document->addScriptDeclaration($script);
	}
	
	$output = '';
	$output .= '<div class="phocagalleryslideshow">' . "\n";
	if ($siteLink != '') {
		$output .= '<a href="'.$siteLink.'" ><div id="phocaGallerySlideshowP'.$c.'"></div></a>'. "\n";
	} else {
		$output .= '<div id="phocaGallerySlideshowP'.$c.'"></div>';
	}
	
	$output .='</div>';	
	
	$c++;
} else {
	$output .= JText::_('PLG_CONTENT_PHOCAGALLERYSLIDESHOW_THERE_IS_NO_IMAGE_OR_CATEGORY_IS_UNPUBLISHED_OR_NOT_AUTHORIZED');
}

// END OUTPUT


				$article->text = preg_replace($regex_all, $output, $article->text, 1);
			}
		}
		return true;
	}
}
?>