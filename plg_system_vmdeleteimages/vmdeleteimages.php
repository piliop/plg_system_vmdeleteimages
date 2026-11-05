<?php
/**
 * System plugin: adds a "delete all images" button to the VirtueMart product
 * edit page (Product Images tab). Files and DB rows are removed via com_ajax.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

class PlgSystemVmdeleteimages extends CMSPlugin
{
	protected $autoloadLanguage = true;

	public function onAfterRender()
	{
		$app = Factory::getApplication();

		if (!$app->isClient('administrator') || $app->input->getCmd('format', 'html') !== 'html')
		{
			return;
		}

		$input = $app->input;

		if ($input->getCmd('option') !== 'com_virtuemart' || $input->getCmd('view') !== 'product')
		{
			return;
		}

		$user = $app->getIdentity();

		if (!$user->authorise('core.edit', 'com_virtuemart') && !$user->authorise('core.admin', 'com_virtuemart'))
		{
			return;
		}

		$cfg = [
			'url'      => Uri::base() . 'index.php?option=com_ajax&plugin=vmdeleteimages&group=system&format=json',
			'token'    => Session::getFormToken(),
			'tabTitle' => Text::_('COM_VIRTUEMART_PRODUCT_FORM_PRODUCT_IMAGES_LBL'),
			'label'    => Text::_('PLG_SYSTEM_VMDELETEIMAGES_BUTTON'),
			'confirm'  => Text::_('PLG_SYSTEM_VMDELETEIMAGES_CONFIRM'),
			'error'    => Text::_('PLG_SYSTEM_VMDELETEIMAGES_ERROR'),
		];

		$js = <<<'JS'
<script>
window.addEventListener('load', function () {
	var cfg = __CFG__;
	var idInput = document.querySelector('input[type="hidden"][name="virtuemart_product_id"], input[name="virtuemart_product_id"]:not([type="checkbox"])');
	var productId = idInput ? parseInt(idInput.value, 10) : 0;
	if (!productId) { console.log('[vmdeleteimages] product id not found'); return; }

	var tries = 0;
	(function init() {
		var target = findImagesPane();
		if (!target) {
			if (++tries < 20) { setTimeout(init, 500); } else { console.log('[vmdeleteimages] images tab not found'); }
			return;
		}
		if (!target.querySelector('.vm-delete-all-images')) { addButton(target); }
	})();

	// The images tab is the pane holding the media handler (file upload / media ids), whatever the tab markup is.
	function findImagesPane() {
		var anchor = document.querySelector('input[type="file"][name*="media"], input[type="file"][name*="upload"], input[name*="virtuemart_media_id"], input[name^="media"], .vm-file-handler, [id*="media_"], [id*="file_"]');
		if (!anchor) { anchor = document.querySelector('form#adminForm input[type="file"]'); }
		if (!anchor) { return null; }
		return anchor.closest('[role="tabpanel"], .tabs, .tab-pane, .uk-switcher > *, li') || anchor.parentElement;
	}

	function addButton(target) {
	var btn = document.createElement('button');
	btn.type = 'button';
	btn.className = 'btn btn-danger vm-delete-all-images';
	btn.style.margin = '8px 0';
	btn.textContent = cfg.label;
	btn.addEventListener('click', function () {
		if (!window.confirm(cfg.confirm)) { return; }
		var body = new URLSearchParams();
		body.set('virtuemart_product_id', productId);
		body.set(cfg.token, '1');
		btn.disabled = true;
		fetch(cfg.url, {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j || !j.success) { throw new Error(j && j.message ? j.message : cfg.error); }
				window.alert(j.data || '');
				window.location.reload();
			})
			.catch(function (e) { btn.disabled = false; window.alert(e.message || cfg.error); });
	});
	target.insertBefore(btn, target.firstChild);
	}
});
</script>
JS;

		$js = str_replace('__CFG__', json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), $js);
		$body = $app->getBody();
		$pos = strripos($body, '</body>');

		$app->setBody($pos === false ? $body . $js : substr_replace($body, $js, $pos, 0));
	}

	/**
	 * com_ajax entry point: index.php?option=com_ajax&plugin=vmdeleteimages&group=system&format=json
	 */
	public function onAjaxVmdeleteimages()
	{
		$app = Factory::getApplication();

		if (!$app->isClient('administrator'))
		{
			throw new \RuntimeException('Forbidden', 403);
		}

		if (!Session::checkToken('post'))
		{
			throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
		}

		$user = $app->getIdentity();

		if (!$user->authorise('core.edit', 'com_virtuemart') && !$user->authorise('core.admin', 'com_virtuemart'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$productId = $app->input->post->getInt('virtuemart_product_id', 0);

		if (!$productId)
		{
			throw new \RuntimeException(Text::_('PLG_SYSTEM_VMDELETEIMAGES_ERROR'), 400);
		}

		$db = Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('virtuemart_media_id'))
			->from($db->quoteName('#__virtuemart_product_medias'))
			->where($db->quoteName('virtuemart_product_id') . ' = ' . $productId);
		$mediaIds = array_map('intval', $db->setQuery($query)->loadColumn());

		$deletedMedia = 0;
		$deletedFiles = 0;

		foreach ($mediaIds as $mediaId)
		{
			$db->setQuery(
				$db->getQuery(true)->delete($db->quoteName('#__virtuemart_product_medias'))
					->where($db->quoteName('virtuemart_product_id') . ' = ' . $productId)
					->where($db->quoteName('virtuemart_media_id') . ' = ' . $mediaId)
			)->execute();

			// Keep media still used by other products, categories or manufacturers.
			if ($this->isMediaUsed($db, $mediaId))
			{
				continue;
			}

			$db->setQuery(
				$db->getQuery(true)->select($db->quoteName(['file_url', 'file_url_thumb']))
					->from($db->quoteName('#__virtuemart_medias'))
					->where($db->quoteName('virtuemart_media_id') . ' = ' . $mediaId)
			);
			$row = $db->loadObject();

			if ($row)
			{
				foreach ([$row->file_url, $row->file_url_thumb] as $url)
				{
					if ($this->deleteFile((string) $url))
					{
						$deletedFiles++;
					}
				}
			}

			$db->setQuery(
				$db->getQuery(true)->delete($db->quoteName('#__virtuemart_medias'))
					->where($db->quoteName('virtuemart_media_id') . ' = ' . $mediaId)
			)->execute();
			$deletedMedia++;
		}

		return Text::sprintf('PLG_SYSTEM_VMDELETEIMAGES_DONE', $deletedMedia, $deletedFiles);
	}

	private function isMediaUsed($db, int $mediaId): bool
	{
		foreach (['#__virtuemart_product_medias', '#__virtuemart_category_medias', '#__virtuemart_manufacturer_medias'] as $table)
		{
			$count = (int) $db->setQuery(
				$db->getQuery(true)->select('COUNT(*)')->from($db->quoteName($table))
					->where($db->quoteName('virtuemart_media_id') . ' = ' . $mediaId)
			)->loadResult();

			if ($count > 0)
			{
				return true;
			}
		}

		return false;
	}

	private function deleteFile(string $url): bool
	{
		$url = trim(str_replace('\\', '/', $url), '/');

		// Only relative paths inside the site; never traverse upward.
		if ($url === '' || preg_match('#(^|/)\.\.(/|$)#', $url) || preg_match('#^[a-z]+://#i', $url))
		{
			return false;
		}

		$path = JPATH_ROOT . '/' . $url;

		if (!is_file($path))
		{
			return false;
		}

		$real = realpath($path);
		$root = realpath(JPATH_ROOT);

		if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0)
		{
			return false;
		}

		return @unlink($real);
	}
}
