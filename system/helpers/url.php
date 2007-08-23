<?php

class url {

	public static function base_url()
	{
		return rtrim(Config::item('core.base_url'), '/').'/';
	}

	public static function site_url($uri)
	{
		$uri = trim($uri, '/');

		$index_page = Config::item('core.index_page').'/';
		$url_suffix = Config::item('core.url_suffix');

		return self::base_url().$index_page.$uri.$url_suffix;
	}

	public static function title($title, $separator = 'dash')
	{
		$separator = ($separator == 'dash') ? '-' : '_';
		
		// Replace all dashes, underscores and whitespace by the separator
		$title = preg_replace('/[-_\s]+/', $separator, $title);
		// Replace accented characters by their unaccented equivalents
		$title = utf8::accents_to_ascii($title);
		// Remove all characters that are not a-z, 0-9, or the separator
		$title = preg_replace('/[^a-zA-Z0-9'.$separator.']/', '', $title);
		
		return $title;
	}

	public static function anchor($uri, $title = FALSE, $attributes = FALSE)
	{
		if ( ! is_array($uri))
		{
			$site_url = (strpos($uri, '://') === FALSE) ? self::site_url($uri) : $uri;
		}
		else
		{
			$site_url = self::site_url($uri);
		}

		if ($title == '')
		{
			$title = $site_url;
		}

		$attributes = ($attributes == TRUE) ? Kohana::attributes($attributes) : '';

		return '<a href="'.$site_url.'"'.$attributes.'>'.$title.'</a>';
	}

	public static function redirect($uri = '', $method = '302')
	{
		if (strpos($uri, '://') === FALSE)
		{
			$uri = self::site_url($uri);
		}

		if ($method == 'refresh')
		{
			header('Refresh: 0; url='. $uri);
		}
		else
		{
			$codes = array(
				'300' => 'Multiple Choices',
				'301' => 'Moved Permanently',
				'302' => 'Found',
				'303' => 'See Other',
				'304' => 'Not Modified',
				'305' => 'Use Proxy',
				'307' => 'Temporary Redirect'
			);

			$method = (isset($codes[$method])) ? $method : '302';

			header('HTTP/1.1 '.$method.' '.$codes[$method]);
			header('Location: '.$uri);
		}

		/**
		 * @todo localize this
		 */
		exit('You should have been redirected to <a href="'.$uri.'">'.$uri.'</a>.');
	}



}
