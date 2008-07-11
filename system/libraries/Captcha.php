<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Captcha library.
 *
 * $Id$
 *
 * @package    Captcha
 * @author     Kohana Team
 * @copyright  (c) 2007-2008 Kohana Team
 * @license    http://kohanaphp.com/license.html
 */
class Captcha_Core {

	// Captcha singleton
	protected static $instance;

	// Style-dependent Captcha driver
	protected $driver;

	// Config values
	public static $config = array
	(
		'style'      => 'basic',
		'width'      => 150,
		'height'     => 50,
		'complexity' => 4,
		'background' => '',
		'font'       => '',
	);

	// The Captcha challenge answer, the text the user is supposed to enter
	public static $response;

	/**
	 * Singleton instance of Captcha.
	 *
	 * @return  object
	 */
	public static function instance()
	{
		// Create the instance if it does not exist
		if (empty(self::$instance))
		{
			self::$instance = new Captcha;
		}

		return self::$instance;
	}

	/**
	 * Constructs and returns a new Captcha object.
	 *
	 * @param   string|array  config group or settings
	 * @return  object
	 */
	public function factory($config = array())
	{
		return new Captcha($config);
	}

	/**
	 * Constructs a new Captcha object.
	 *
	 * @throws  Kohana_Exception
	 * @param   string|array  config group or settings
	 * @return  void
	 */
	public function __construct($config = array())
	{
		static $gd2_check;

		// Check once for GD2 support
		if ($gd2_check === NULL AND ($gd2_check = function_exists('imagegd2')) === FALSE)
			throw new Kohana_Exception('captcha.requires_GD2');

		// Only config group name given
		if (is_string($config))
		{
			$config = array('group' => $config);
		}
		// No custom config group name given
		elseif ( ! isset($config['group']))
		{
			$config['group'] = 'default';
		}

		// Load and validate config group
		if ( ! is_array($group_config = Config::item('captcha.'.$config['group'])))
			throw new Kohana_Exception('captcha.undefined_group', $config['group']);

		// All captcha config groups inherit default config group
		if ($config['group'] !== 'default')
		{
			// Load and validate default config group
			if ( ! is_array($default_config = Config::item('captcha.default')))
				throw new Kohana_Exception('captcha.undefined_group', 'default');

			// Merge config group with default config group
			$group_config += $default_config;
		}

		// Merge custom config items with config group
		$config += $group_config;

		// Assign config values to the object
		foreach ($config as $key => $value)
		{
			if (array_key_exists($key, self::$config))
			{
				self::$config[$key] = $value;
			}
		}

		// If using a background image, check if it exists
		if ( ! empty($config['background']))
		{
			self::$config['background'] = str_replace('\\', '/', realpath($config['background']));

			if ( ! file_exists(self::$config['background']))
				throw new Kohana_Exception('captcha.file_not_found', self::$config['background']);
		}

		// If using a font, check if it exists
		if ( ! empty($config['font']))
		{
			self::$config['font'] = str_replace('\\', '/', realpath($config['font']));

			if ( ! file_exists(self::$config['font']))
				throw new Kohana_Exception('captcha.file_not_found', self::$config['font']);
		}

		// Set driver name
		$driver = 'Captcha_'.ucfirst($config['style']).'_Driver';

		// Load the driver
		if ( ! Kohana::auto_load($driver))
			throw new Kohana_Exception('core.driver_not_found', $config['style'], get_class($this));

		// Initialize the driver
		$this->driver = new $driver();

		// Validate the driver
		if ( ! ($this->driver instanceof Captcha_Driver))
			throw new Kohana_Exception('core.driver_implements', $type, get_class($this), 'Captcha_Driver');

		// Generate a new Captcha challenge
		self::$response = (string) $this->driver->generate_challenge();

		// Store the Captcha response in a session
		Event::add('system.post_controller', array($this, 'update_response_session'));

		Log::add('debug', 'Captcha Library initialized');
	}

	/**
	 * Stores the response for the current Captcha challenge in a session so it is available
	 * on the next page load for Captcha::valid(). This method is called after controller
	 * execution (system.post_controller event) in order not to overwrite itself too soon.
	 *
	 * @return  void
	 */
	public function update_response_session()
	{
		Session::instance()->set('captcha_response', sha1(strtoupper(self::$response)));
	}

	/**
	 * Validates a Captcha response and updates response counter.
	 *
	 * @param   string   captcha response
	 * @return  boolean
	 */
	public static function valid($response)
	{
		// Maximum one count per page load
		static $counted;

		// Challenge result
		$result = (sha1(strtoupper($response)) === Session::instance()->get('captcha_response'));

		// Increment response counter
		if ($counted !== TRUE)
		{
			$counted = TRUE;

			// Valid response
			if ($result === TRUE)
			{
				self::instance()->valid_count(Session::instance()->get('captcha_valid_count') + 1);
			}
			// Invalid response
			else
			{
				self::instance()->invalid_count(Session::instance()->get('captcha_invalid_count') + 1);
			}
		}

		return $result;
	}

	/**
	 * Gets or sets the number of valid Captcha responses for this session.
	 *
	 * @param   integer  new counter value
	 * @param   boolean  trigger invalid counter (for internal use only)
	 * @return  integer  counter value
	 */
	public function valid_count($new_count = NULL, $invalid = FALSE)
	{
		// Pick the right session to use
		$session = ($invalid === TRUE) ? 'captcha_invalid_count' : 'captcha_valid_count';

		// Update counter
		if ($new_count !== NULL)
		{
			$new_count = (int) $new_count;

			// Reset counter = delete session
			if ($new_count < 1)
			{
				Session::instance()->delete($session);
			}
			// Set counter to new value
			else
			{
				Session::instance()->set($session, (int) $new_count);
			}

			// Return new count
			return (int) $new_count;
		}

		// Return current count
		return (int) Session::instance()->get($session);
	}

	/**
	 * Gets or sets the number of invalid Captcha responses for this session.
	 *
	 * @param   integer  new counter value
	 * @return  integer  counter value
	 */
	public function invalid_count($new_count = NULL)
	{
		return $this->valid_count($new_count, TRUE);
	}

	/**
	 * Resets the Captcha response counters and removes the count sessions.
	 *
	 * @return  void
	 */
	public function reset_count()
	{
		$this->valid_count(0);
		$this->valid_count(0, TRUE);
	}

	/**
	 * Output the Captcha challenge.
	 *
	 * @param   boolean  TRUE to output html, e.g. <img src="#" />
	 * @return  mixed    html string or void
	 */
	public function render($html = TRUE)
	{
		return $this->driver->render($html);
	}

	/**
	 * Magically outputs the Captcha challenge.
	 *
	 * @return  mixed
	 */
	public function __toString()
	{
		return $this->render();
	}

} // End Captcha Class