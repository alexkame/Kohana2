<?php  if (!defined('SYSPATH')) exit('No direct script access allowed');
/**
 * Kohana
 *
 * An open source application development framework for PHP 4.3.2 or newer
 *
 * NOTE: This file has been modified from the original CodeIgniter version for
 * the Kohana framework by the Kohana Development Team.
 *
 * @package          Kohana
 * @author           Kohana Development Team
 * @copyright        Copyright (c) 2007, Kohana Framework Team
 * @link             http://kohanaphp.com
 * @license          http://kohanaphp.com/user_guide/license.html
 * @since            Version 1.0
 * @orig_package     CodeIgniter
 * @orig_author      Rick Ellis
 * @orig_copyright   Copyright (c) 2006, EllisLab, Inc.
 * @orig_license     http://www.codeignitor.com/user_guide/license.html
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * Kohana Hooks Class
 *
 * Provides a mechanism to extend the base system without hacking.
 *
 * @package		Kohana
 * @subpackage	Libraries
 * @category	Libraries
 * @author		Rick Ellis
 * @link		http://kohanaphp.com/user_guide/libraries/encryption.html
 */
class Core_Hooks {

	var $enabled 		= FALSE;
	var $hooks   		= array();
	var $in_progress	= FALSE;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->_initialize();
		Log::add('debug', 'Hooks Class Initialized');
	}

	// --------------------------------------------------------------------

	/**
	 * Initialize the Hooks Preferences
	 *
	 * @access	private
	 * @return	void
	 */
  	private function _initialize()
  	{
		$CFG = load_class('Config');

		// If hooks are not enabled in the config file
		// there is nothing else to do
		if ($CFG->item('enable_hooks') == FALSE)
			return;

		// Grab the "hooks" definition file.
		if(($abs_resource_path = find_resource('hooks'.EXT,'config')) !== FALSE)
			include($abs_resource_path);

		// If there are no hooks, we're done.
		if ( ! isset($hook) OR ! is_array($hook))
			return;

		$this->hooks = $hook;
		$this->enabled = TRUE;
  	}

	// --------------------------------------------------------------------

	/**
	 * Call Hook
	 *
	 * Calls a particular hook
	 *
	 * @access	private
	 * @param	string	the hook name
	 * @return	mixed
	 */
	private function _call_hook($which = '')
	{
		if ( ! $this->enabled OR ! isset($this->hooks[$which]))
		{
			return FALSE;
		}

		if (isset($this->hooks[$which][0]) AND is_array($this->hooks[$which][0]))
		{
			foreach ($this->hooks[$which] as $val)
			{
				$this->_run_hook($val);
			}
		}
		else
		{
			$this->_run_hook($this->hooks[$which]);
		}

		return TRUE;
	}

	// --------------------------------------------------------------------

	/**
	 * Run Hook
	 *
	 * Runs a particular hook
	 *
	 * @access	private
	 * @param	array	the hook details
	 * @return	bool
	 */
	private function _run_hook($data)
	{
		if ( ! is_array($data))
			return FALSE;

		// -----------------------------------
		// Safety - Prevents run-away loops
		// -----------------------------------

		// If the script being called happens to have the same
		// hook call within it a loop can happen

		if ($this->in_progress == TRUE)
			return;

		// -----------------------------------
		// Set file path
		// -----------------------------------

		if ( ! isset($data['filepath']) OR ! isset($data['filename']))
			return FALSE;

		if (($filepath = find_resource($data['filename'],$data['filepath'])) === FALSE)
			return FALSE;

		// -----------------------------------
		// Set class/function name
		// -----------------------------------

		$class		= FALSE;
		$function	= FALSE;
		$params		= '';

		if (isset($data['class']) AND $data['class'] != '')
		{
			$class = $data['class'];
		}

		if (isset($data['function']))
		{
			$function = $data['function'];
		}

		if (isset($data['params']))
		{
			$params = $data['params'];
		}

		if ($class === FALSE AND $function === FALSE)
			return FALSE;

		// -----------------------------------
		// Set the in_progress flag
		// -----------------------------------

		$this->in_progress = TRUE;

		// -----------------------------------
		// Call the requested class and/or function
		// -----------------------------------

		if ($class !== FALSE)
		{
			if ( ! class_exists($class))
			{
				require($filepath);
			}

			$HOOK = new $class;
			$HOOK->$function($params);
		}
		else
		{
			if ( ! function_exists($function))
			{
				require($filepath);
			}

			$function($params);
		}

		$this->in_progress = FALSE;
		return TRUE;
	}

}

// END Core_Hooks class
?>