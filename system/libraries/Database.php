<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Class: Database
 *  Provides database access in a platform agnostic way, using simple query building blocks.
 *
 * Kohana Source Code:
 *  author    - Kohana Team
 *  copyright - (c) 2007 Kohana Team
 *  copyright - (c) 2006 EllisLab, Inc.
 *  license   - <http://www.codeigniter.com/user_guide/license.html>
 */
class Database_Core {

	// Global benchmark
	public static $benchmarks = array();

	// Configuration
	protected $config = array
	(
		'benchmark'     => TRUE,
		'persistent'    => FALSE,
		'connection'    => '',
		'character_set' => 'utf8',
		'table_prefix'  => '',
		'object'        => TRUE,
		'cache'         => FALSE
	);

	// Database driver object
	protected $driver;
	protected $link;

	// Un-compiled parts of the SQL query
	protected $select     = array();
	protected $set        = array();
	protected $from       = array();
	protected $join       = array();
	protected $where      = array();
	protected $orderby    = array();
	protected $order      = array();
	protected $groupby    = array();
	protected $having     = array();
	protected $distinct   = FALSE;
	protected $limit      = FALSE;
	protected $offset     = FALSE;
	protected $last_query = '';

	/**
	 * Constructor: __construct
	 *  Sets up the database configuration, loads the <Database_Driver>.
	 *
	 * Throws:
	 *  <Kohana_Database_Exception> if there is no database group, an invalid DSN is supplied,
	 *  or the requested driver doesn't exist.
	 */
	public function __construct($config = array())
	{
		if (empty($config))
		{
			// Load the default group
			$config = Config::item('database.default');
		}
		elseif (is_string($config))
		{
			// The config is a DSN string
			if (strpos($config, '://') !== FALSE)
			{
				$config = array('connection' => $config);
			}
			// The config is a group name
			else
			{
				$name = $config;

				// Test the config group name
				if (($config = Config::item('database.'.$config)) === NULL)
					throw new Kohana_Database_Exception('database.undefined_group', $name);
			}
		}

		// Merge the default config with the passed config
		$this->config = array_merge($this->config, $config);

		// Make sure the connection is valid
		if (strpos($this->config['connection'], '://') === FALSE)
			throw new Kohana_Exception('database.invalid_dsn', $this->config['connection']);

		// Parse the DSN, creating an array to hold the connection parameters
		$db = array
		(
			'type'     => FALSE,
			'user'     => FALSE,
			'pass'     => FALSE,
			'host'     => FALSE,
			'port'     => FALSE,
			'socket'   => FALSE,
			'database' => FALSE
		);

		// Get the protocol and arguments
		list ($db['type'], $connection) = explode('://', $this->config['connection'], 2);

		if (strpos($connection, '@') !== FALSE)
		{
			// Get the username and password
			list ($db['pass'], $connection) = explode('@', $connection, 2);
			// Check if a password is supplied
			$logindata = explode(':', $db['pass'], 2);
			$db['pass'] = (count($logindata) > 1) ? $logindata[1] : '';
			$db['user'] = $logindata[0];

			// Prepare for finding the database
			$connection = explode('/', $connection);

			// Find the database name
			$db['database'] = array_pop($connection);

			// Reset connection string
			$connection = implode('/', $connection);

			// Find the socket
			if (preg_match('/^unix\([^)]++\)/', $connection))
			{
				// This one is a little hairy: we explode based on the end of
				// the socket, removing the 'unix(' from the connection string
				list ($db['socket'], $connection) = explode(')', substr($connection, 5), 2);
			}
			elseif (strpos($connection, ':') !== FALSE)
			{
				// Fetch the host and port name
				list ($db['host'], $db['port']) = explode(':', $connection, 2);
			}
			else
			{
				$db['host'] = $connection;
			}
		}
		else
		{
			// File connection
			$connection = explode('/', $connection);

			// Find database file name
			$db['database'] = array_pop($connection);

			// Find database directory name
			$db['socket'] = implode('/', $connection).'/';
		}

		// Reset the connection array to the database config
		$this->config['connection'] = $db;

		try
		{
			// Set driver name
			$driver = 'Database_'.ucfirst($this->config['connection']['type']).'_Driver';

			// Manually load so that exceptions can be caught
			require_once Kohana::find_file('libraries/drivers', substr($driver, 0, -7), TRUE);
		}
		catch (Kohana_Exception $e)
		{
			throw new Kohana_Database_Exception('database.driver_not_supported', $this->config['connection']['type']);
		}

		// Initialize the driver
		$this->driver = new $driver($this->config);

		// Validate the driver
		if ( ! ($this->driver instanceof Database_Driver))
			throw new Kohana_Exception('database.driver_not_supported', 'Database drivers must use the Database_Driver interface.');

		Log::add('debug', 'Database Library initialized');
	}

	/**
	 * Method: connect
	 *  Simple connect method to get the database queries up and running.
	 */
	public function connect()
	{
		// A link can be a resource or an object
		if ( ! is_resource($this->link) AND ! is_object($this->link))
		{
			$this->link = $this->driver->connect();
			if ( ! is_resource($this->link) AND ! is_object($this->link))
				throw new Kohana_Exception('database.connection', $this->driver->show_error());
		}
	}

	/**
	 * Method: query
	 *  Runs a query into the driver and returns the result.
	 *
	 * Parameters:
	 *  sql - SQL query to execute
	 *
	 * Returns:
	 *  <Database_Result> object
	 */
	public function query($sql = '')
	{
		if ($sql == '') return FALSE;

		// No link? Connect!
		$this->link or $this->connect();

		// Start the benchmark
		$start = microtime(TRUE);

		if (func_num_args() > 1) //if we have more than one argument ($sql)
		{
			$argv = func_get_args();
			$binds = (is_array(next($argv))) ? current($argv) : array_slice($argv, 1);
		}

		// Compile binds if needed
		if (isset($binds))
		{
			$sql = $this->compile_binds($sql, $binds);
		}

		// Fetch the result
		$result = $this->driver->query($this->last_query = $sql);

		// Stop the benchmark
		$stop = microtime(TRUE);

		if ($this->config['benchmark'] == TRUE)
		{
			// Benchmark the query
			self::$benchmarks[] = array('query' => $sql, 'time' => $stop - $start, 'rows' => count($result));
		}

		return $result;
	}

	/**
	 * Method: select
	 *  Selects the column names for a database <Database.query>.
	 *
	 * Parameters:
	 *  sql - string or array of column names to <Database.select>
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function select($sql = '*')
	{
		if (func_num_args() > 1)
		{
			$sql = func_get_args();
		}
		elseif (is_string($sql))
		{
			$sql = explode(',', $sql);
		}
		else
		{
			$sql = (array) $sql;
		}

		foreach($sql as $val)
		{
			if (($val = trim($val)) == '') continue;

			if (strpos($val, '(') === FALSE AND $val !== '*')
			{
				$val = (strpos($val, '.') !== FALSE) ? $this->config['table_prefix'].$val : $val;
				$val = $this->driver->escape_column($val);
			}

			$this->select[] = $val;
		}

		return $this;
	}

	/**
	 * Method: from
	 *  Selects the from table(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  sql - string or array of tables to <Database.select>
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function from($sql)
	{
		foreach((array) $sql as $val)
		{
			if (($val = trim($val)) == '') continue;

			$this->from[] = $this->driver->escape_table($this->config['table_prefix'].$val);
		}

		return $this;
	}

	/**
	 * Method: join
	 *  Generates the JOIN portion of the query.
	 *
	 * Parameters:
	 *  table - table name
	 *  cond  - join condition
	 *  type  - type of join (optional)
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function join($table, $cond, $type = '')
	{
		if ($type != '')
		{
			$type = strtoupper(trim($type));

			if ( ! in_array($type, array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER'), TRUE))
			{
				$type = '';
			}
			else
			{
				$type .= ' ';
			}
		}

		if (preg_match('/([a-z.].+)\s+=\s+([a-z.].+)/i', $cond, $where))
		{
			$cond = $this->driver->escape_column($this->config['table_prefix'].$where[1]).
			        ' = '.
			        $this->driver->escape_column($this->config['table_prefix'].$where[2]);
		}

		$this->join[] = $type.'JOIN '.$this->driver->escape_column($this->config['table_prefix'].$table).' ON '.$cond;

		return $this;
	}

	/**
	 * Method: where
	 *  Selects the where(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  key   - key name or array of key => value pairs
	 *  value - value to match with key
	 *  quote - disable quoting of WHERE clause
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function where($key, $value = NULL, $quote = TRUE)
	{
		$quote = (func_num_args() < 2 AND ! is_array($key)) ? -1 : $quote;
		$keys  = is_array($key) ? $key : array($key => $value);

		foreach ($keys as $key => $value)
		{
			$key           = (strpos($key, '.') !== FALSE) ? $this->config['table_prefix'].$key : $key;
			$this->where[] = $this->driver->where($key, $value, 'AND ', count($this->where), $quote);
		}

		return $this;
	}

	/**
	 * Method: orwhere
	 *  Selects the or where(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  key   - key name or array of key => value pairs
	 *  value - value to match with key
	 *  quote - disable quoting of WHERE clause
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function orwhere($key, $value = NULL, $quote = TRUE)
	{
		$quote = (func_num_args() < 2 AND ! is_array($key)) ? -1 : $quote;
		$keys  = is_array($key) ? $key : array($key => $value);

		foreach ($keys as $key => $value)
		{
			$key           = (strpos($key, '.') !== FALSE) ? $this->config['table_prefix'].$key : $key;
			$this->where[] = $this->driver->where($key, $value, 'OR ', count($this->where), $quote);
		}

		return $this;
	}

	/**
	 * Method: like
	 *  Selects the like(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - like value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function like($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->like($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: orlike
	 *  Selects the or like(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - like value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function orlike($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->like($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: notlike
	 *  Selects the not like(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - like value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function notlike($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notlike($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: ornotlike
	 *  Selects the or not like(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - like value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function ornotlike($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notlike($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: regex
	 *  Selects the like(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - like value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function regex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->regex($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: orregex
	 *  Selects the or like(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - like value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function orregex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->regex($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: notregex
	 *  Selects the not regex(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - regex value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function notregex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notregex($field, $match, 'AND ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: ornotregex
	 *  Selects the or not regex(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  field - field name or array of field => match pairs
	 *  match - regex value to match with field
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function ornotregex($field, $match = '')
	{
		$fields = is_array($field) ? $field : array($field => $match);

		foreach ($fields as $field => $match)
		{
			$field         = (strpos($field, '.') !== FALSE) ? $this->config['table_prefix'].$field : $field;
			$this->where[] = $this->driver->notregex($field, $match, 'OR ', count($this->where));
		}

		return $this;
	}

	/**
	 * Method: groupby
	 *  chooses the column to group by in a select <Database.query>.
	 *
	 * Parameters:
	 *  by - column name to group by
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function groupby($by)
	{
		if ( ! is_array($by))
		{
			$by = explode(',', (string) $by);
		}

		foreach ($by as $val)
		{
			$val = trim($val);

			if ($val != '')
			{
				$this->groupby[] = $val;
			}
		}

		return $this;
	}

	/**
	 * Method: having
	 *  Selects the having(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  key   - key name or array of key => value pairs
	 *  value - value to match with key
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function having($key, $value = '')
	{
	    $this->having = array_merge($this->having, $this->driver->having($key, $value, 'AND'));
		return $this;
	}

	/**
	 * Method: orhaving
	 *  Selects the or having(s) for a database <Database.query>.
	 *
	 * Parameters:
	 *  key   - key name or array of key => value pairs
	 *  value - value to match with key
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function orhaving($key, $value = '')
	{
		$this->having = array_merge($this->having, $this->driver->having($key, $value, 'OR'));
		return $this;
	}

	/**
	 * Method: orderby
	 *  Chooses which column(s) to order the <Database.select> <Database.query> by.
	 *
	 * Parameters:
	 *  orderby   - column(s) to order on, can be an array, single column, or comma seperated list of columns
	 *  direction - direction of the order
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function orderby($orderby, $direction = '')
	{
		$direction = strtoupper(trim($direction));

		if ($direction != '')
		{
			$direction = (in_array($direction, array('ASC', 'DESC', 'RAND()', 'NULL'))) ? ' '.$direction : ' ASC';
		}

		if (empty($orderby))
		{
			$this->orderby[] = $direction;
			return $this;
		}

		if ( ! is_array($orderby))
		{
			$orderby = explode(',', (string) $orderby);
		}

		$order = array();
		foreach ($orderby as $field)
		{
			$field = trim($field);

			if ($field != '')
			{
				$order[] = $this->driver->escape_column($field);
			}
		}
		$this->orderby[] = implode(',', $order).$direction;
		return $this;
	}

	/**
	 * Method: limit
	 *  Selects the limit section of a <Database.query>.
	 *
	 * Parameters:
	 *  value  - number of rows to limit result to
	 *  offset - offset in result to start returning rows from
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function limit($limit, $offset = FALSE)
	{
		$this->limit  = (int) $limit;
		$this->offset = (int) $offset;

		return $this;
	}

	/**
	 * Method: offset
	 *  Sets the offset portion of a <Database.query>.
	 *
	 * Parameters:
	 *  value - offset value
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function offset($value)
	{
		$this->offset = (int) $value;
		return $this;
	}

	/**
	 * Method: set
	 *  Allows key/value pairs to be set for <Database.insert>ing or <Database.update>ing.
	 *
	 * Parameters:
	 *  key   - key name or array of key => value pairs
	 *  value - value to match with key
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function set($key, $value = '')
	{
		if ( ! is_array($key))
		{
			$key = array($key => $value);
		}

		foreach ($key as $k => $v)
		{
			$this->set[$k] = $this->driver->escape($v);
		}

		return $this;
	}

	/**
	 * Method: get
	 *  Compiles the <Database.select> statement based on
	 *  the other functions called and runs the query.
	 *
	 * Parameters:
	 *  table  - table name
	 *  limit  - <Database.limit> clause
	 *  offset - <Database.offset> clause
	 *
	 * Returns:
	 *  The <Database> object
	 */
	public function get($table = '', $limit = NULL, $offset = NULL)
	{
		if ($table != '')
		{
			$this->from($table);
		}

		if ( ! is_null($limit))
		{
			$this->limit($limit, $offset);
		}

		$sql = $this->driver->compile_select(get_object_vars($this));

		$result = $this->query($sql);

		$this->reset_select();
		$this->last_query = $sql;

		return $result;
	}

	/**
	 * Method: getwhere
	 *  Compiles the <Database.select> statement based on
	 *  the other functions called and runs the <Database.query>.
	 *
	 * Parameters:
	 *  table  - table name
	 *  where  - <Database.where> clause
	 *  limit  - <Database.limit) clause
	 *  offset - <Database.offset) clause
	 *
	 * Returns:
	 *  <Database_Result> object
	 */
	public function getwhere($table = '', $where = NULL, $limit = NULL, $offset = NULL)
	{
		if ($table != '')
		{
			$this->from($table);
		}

		if ( ! is_null($where))
		{
			$this->where($where);
		}

		if ( ! is_null($limit))
		{
			$this->limit($limit, $offset);
		}

		$sql = $this->driver->compile_select(get_object_vars($this));

		$result = $this->query($sql);
		$this->reset_select();
		return $result;
	}

	/**
	 * Method: insert
	 *  Compiles an insert string and runs the <Database.query>.
	 *
	 * Parameters:
	 *  table - table name
	 *  set   - array of key/value pairs to insert
	 *
	 * Returns:
	 *  <Database_Result> object
	 */
	public function insert($table = '', $set = NULL)
	{
		if ( ! is_null($set))
		{
			$this->set($set);
		}

		if ($this->set == NULL)
		{
			Log::add('debug', Kohana::lang('database.must_use_set'));
			throw new Kohana_Database_Exception('database.must_use_set');
		}

		if ($table == '')
		{
			if ( ! isset($this->from[0]))
			{
				Log::add('debug', Kohana::lang('database.must_use_table'));
				throw new Kohana_Database_Exception('database.must_use_table');
			}

			$table = $this->from[0];
		}

		$sql = $this->driver->insert($this->config['table_prefix'].$table, array_keys($this->set), array_values($this->set));

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Method: merge
	 *  Compiles an merge string and runs the <Database.query>.
	 *
	 * Parameters:
	 *  table - table name
	 *  set   - array of key/value pairs to merge
	 *
	 * Returns:
	 *  <Database_Result> object
	 */
	public function merge($table = '', $set = NULL)
	{
		if ( ! is_null($set))
		{
			$this->set($set);
		}

		if ($this->set == NULL)
		{
			Log::add('debug', Kohana::lang('database.must_use_set'));
			throw new Kohana_Database_Exception('database.must_use_set');
		}

		if ($table == '')
		{
			if ( ! isset($this->from[0]))
			{
				Log::add('debug', Kohana::lang('database.must_use_table'));
				throw new Kohana_Database_Exception('database.must_use_table');
			}

			$table = $this->from[0];
		}

		$sql = $this->driver->merge($this->config['table_prefix'].$table, array_keys($this->set), array_values($this->set));

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Method: update
	 *  Compiles an update string and runs the <Database.query>.
	 *
	 * Parameters:
	 *  table - table name
	 *  set   - associative array of update values
	 *  where - <Database.where> clause
	 *
	 * Returns:
	 *  <Database_Result> object
	 */
	public function update($table = '', $set = NULL, $where = NULL)
	{
		if ( is_array($set))
		{
			$this->set($set);
		}

		if ( ! is_null($where))
		{
			$this->where($where);
		}

		if ($this->set == FALSE)
		{
			Log::add('debug', Kohana::lang('database.must_use_set'));
			throw new Kohana_Database_Exception('database.must_use_set');
		}

		if ($table == '')
		{
			if ( ! isset($this->from[0]))
			{
				Log::add('debug', Kohana::lang('database.must_use_table'));
				throw new Kohana_Database_Exception('database.must_use_table');
			}

			$table = $this->from[0];
		}

		$sql = $this->driver->update($this->config['table_prefix'].$table, $this->set, $this->where);

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Method: delete
	 *  Compiles a delete string and runs the <Database.query>.
	 *
	 * Parameters:
	 *  table - table name
	 *  where - <Database.where> clause
	 *
	 * Returns:
	 *  <Database_Result> object
	 */
	public function delete($table = '', $where = NULL)
	{
		if ($table == '')
		{
			if ( ! isset($this->from[0]))
			{
				Log::add('debug', Kohana::lang('database.must_use_table'));
				throw new Kohana_Database_Exception('database.must_use_table');
			}

			$table = $this->from[0];
		}

		if (! is_null($where))
		{
			$this->where($where);
		}

		if (count($this->where) < 1)
		{
			Log::add('debug', Kohana::lang('database.must_use_where'));
			throw new Kohana_Database_Exception('database.must_use_where');
		}

		$sql = $this->driver->delete($this->config['table_prefix'].$table, $this->where);

		$this->reset_write();
		return $this->query($sql);
	}

	/**
	 * Method: last_query
	 *  Returns the last <Database.query> run.
	 *
	 * Returns:
	 *  A string containing the last SQL query
	 */
	public function last_query()
	{
	   return $this->last_query;
	}

	/**
	 * Method: count_records
	 *  Count query records.
	 *
	 * Parameters:
	 *  table - table name
	 *  where - <Database.where> clause (optional)
	 *
	 * Returns:
	 *  Number of records
	 */
	public function count_records($table = FALSE, $where = NULL)
	{
		if (count($this->from) < 1)
		{
			if ($table == FALSE)
			{
				Log::add('debug', Kohana::lang('database.must_use_table'));
				throw new Kohana_Database_Exception('database.must_use_table');
			}

			$this->from($table);
		}

		if ( ! is_null($where))
		{
			$this->where($where);
		}

		$query = $this->select('COUNT(*) AS records_found')->get()->result(TRUE);

		return $query->current()->records_found;
	}

	/**
	 * Method: reset_select
	 *  Resets all private select variables.
	 */
	private function reset_select()
	{
		$this->select   = array();
		$this->from     = array();
		$this->join     = array();
		$this->where    = array();
		$this->orderby  = array();
		$this->groupby  = array();
		$this->having   = array();
		$this->distinct = FALSE;
		$this->limit    = FALSE;
		$this->offset   = FALSE;
	}

	/**
	 * Method: reset_write
	 *  Resets all private insert and update variables.
	 */
	private function reset_write()
	{
		$this->set   = array();
		$this->from  = array();
		$this->where = array();
	}

	/**
	 * Method: list_tables
	 *  Lists all the tables in the current database.
	 *
	 * Returns:
	 *  An array of table names
	 */
	public function list_tables()
	{
		$this->link or $this->connect();

		$this->reset_select();

		return $this->driver->list_tables();
	}

	/**
	 * Method: table_exists
	 *  See if a table exists in the database.
	 *
	 * Parameters:
	 *  table_name - table name
	 *
	 * Returns:
	 *  TRUE or FALSE
	 */
	public function table_exists($table_name)
	{
		return in_array($this->config['table_prefix'].$table_name, $this->list_tables());
	}

	/**
	 * Method: compile_binds
	 *  Combine a SQL statement with the bind values. Used for safe queries.
	 *
	 * Parameters:
	 *  sql   - query to bind to the values
	 *  binds - array of values to bind to the query
	 *
	 * Returns:
	 *  String containing the final <Database.query> to run
	 */
	public function compile_binds($sql, $binds)
	{
		foreach ((array) $binds as $val)
		{
			// If the SQL contains no more bind marks ("?"), we're done.
			if (($next_bind_pos = strpos($sql, '?')) === FALSE)
				break;

			// Properly escape the bind value.
			$val = $this->driver->escape($val);

			// Temporarily replace possible bind marks ("?"), in the bind value itself, with a placeholder.
			$val = str_replace('?', '{%B%}', $val);

			// Replace the first bind mark ("?") with its corresponding value.
			$sql = substr($sql, 0, $next_bind_pos).$val.substr($sql, $next_bind_pos + 1);
		}

		// Restore placeholders.
		return str_replace('{%B%}', '?', $sql);
	}

	/**
	 * Method: field_data
	 *  Get the field data for a database table, along with the field's attributes.
	 *
	 * Parameters:
	 *  table - table name
	 *
	 * Returns:
	 *  Array containing the field data
	 */
	public function field_data($table = '')
	{
		$this->link or $this->connect();

		return $this->driver->field_data($this->config['table_prefix'].$table);
	}

	/**
	 * Method: list_fields
	 *  Get the field data for a database table, along with the field's attributes.
	 *
	 * Parameters:
	 *  table - table name
	 *
	 * Returns:
	 *  Array containing the field data
	 */
	public function list_fields($table = '')
	{
		$this->link or $this->connect();

		return $this->driver->list_fields($this->config['table_prefix'].$table);
	}

	/**
	 * Method: escape
	 *  Escapes a value for a query.
	 *
	 * Parameters:
	 *  value - value to escape
	 *
	 * Returns:
	 *  An escaped version of the value
	 */
	public function escape($value)
	{
		return $this->driver->escape($value);
	}

	/**
	 * Method: escape_str
	 *  Escapes a string for a query.
	 *
	 * Parameters:
	 *  str - string to escape
	 *
	 * Returns:
	 *  An escaped version of the string
	 */
	public function escape_str($str)
	{
		return $this->driver->escape_str($str);
	}

	/**
	* Method: table_prefix
	*  Returns table prefix of current configuration.
	*
	* Returns:
	*  A string containing the table prefix for the database
	*/
	public function table_prefix()
	{
		return $this->config['table_prefix'];
	}

	/**
	 * Method: clear_cache
	 *  Clears the query cache
	 *
	 * Parameters:
	 *  sql - clear cache by SQL statement (TRUE for last query)
	 *
	 */
	public function clear_cache($sql = NULL)
	{
		if ($sql === TRUE)
		{
			$this->driver->clear_cache($this->last_query);
		}
		elseif (is_string($sql))
		{
			$this->driver->clear_cache($sql);
		}
		else
		{
			$this->driver->clear_cache();
		}

		return $this;
	}

	public function stmt_prepare($sql)
	{
		return $this->driver->stmt_prepare($sql, $this->config);
	}

} // End Database Class

/**
 * Class: Kohana Database Exception
 *  Sets the code for a <Database> exception.
 *
 * Kohana Source Code:
 *  author    - Kohana Team
 *  copyright - (c) 2007 Kohana Team
 *  license   - <http://kohanaphp.com/license.html>
 */
class Kohana_Database_Exception extends Kohana_Exception {

	protected $code = E_DATABASE_ERROR;

} // End Kohana Database Exception