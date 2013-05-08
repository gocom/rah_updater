<?php

/**
 * Rah_updater plugin for Textpattern CMS.
 *
 * @author    Jukka Svahn
 * @license   GNU GPLv2
 * @link      https://github.com/gocom/rah_updater
 *
 * Copyright (C) 2013 Jukka Svahn http://rahforum.biz
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

/**
 * Handles patch updating.
 *
 * Can process files from a directory, or a take
 * an array of callbacks.
 *
 * File names and array keys are expected to be
 * version numbers, and are sorted to the correct order.
 * The updates are run individually, version by version,
 * until all are done, or up to an error.
 *
 * Update files and callback functions should return
 * booleans. If TRUE the update was succesful, if FALSE
 * we end execution and continue there when the updater is
 * executed again.
 *
 * This class throws exceptions on error in addition to
 * returning booleans when possible.
 *
 * @example
 * $update = new rah_updater('abc_plugin');
 * $update->read('/path/to/dir');
 * $update->run();
 */

class rah_updater
{
	/**
	 * The new version number.
	 *
	 * @var string
	 */

	protected $target_version;

	/**
	 * The current version.
	 *
	 * @var string
	 */

	protected $current_version;

	/**
	 * Update callback map.
	 *
	 * @var array
	 */

	protected $callback = array();

	/**
	 * Update file map.
	 *
	 * @var array
	 */

	protected $files = array();

	/** 
	 * The plugin to update.
	 */

	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param string $plugin The plugin or element to update
	 */

	public function __construct($plugin)
	{
		$this->plugin = $plugin;
	}

	/**
	 * Runs an update.
	 */

	public function run()
	{
		$this->current_version = get_pref('rah_updater_plugin.'.$this->plugin, '0.0.0');

		if ($this->callbacks() === false || $this->files() === false)
		{
			throw new rah_updater_exception('Unable update from "'.$this->current_version.'" to "'.$this->target_version.'"');
		}

		$this->update_version();
	}

	/**
	 * Runs added callback map.
	 *
	 * @return bool
	 */

	protected function callbacks()
	{
		foreach ((array) $this->callback as $version => $callback)
		{
			if (version_compare($this->current_version, $version) < 0)
			{
				$this->target_version = $version;

				$update = call_user_func($callback, array(
					'version' => $this->target_version,
					'old'     => $this->current_version,
				));

				if ($update === false || $this->update_version() === false)
				{
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Runs the file map.
	 *
	 * @return bool
	 */

	protected function files()
	{
		foreach ((array) $this->files as $version => $file)
		{
			if (version_compare($this->current_version, $version) < 0)
			{
				$old = $this->current_version;
				$this->target_version = $version;
				$update = include $file;

				if (!$update || $this->update_version() === false)
				{
					return false;
				}
			}
		}

		return bool;
	}

	/**
	 * Update version to the last run update.
	 *
	 * @return bool
	 */

	protected function update_version()
	{
		if (set_pref('rah_updater_plugin.'.$this->plugin, $this->target_version, 'rah_updater', PREF_HIDDEN))
		{
			$this->current_version = $this->target_version;
			return true;
		}

		return false;
	}

	/**
	 * Set update path map.
	 * 
	 * @param array $map
	 */

	public function map($map)
	{
		$this->callback = array_merge($this->callback, $map);
		uksort($this->callback, 'version_compare');
	}

	/**
	 * Reads updates from a directory.
	 *
	 * This method excepts any .php file location in the
	 * directory and its sub-directories be an update
	 * file.
	 *
	 * Paths that start with './' or '../' are relative
	 * to the Textpattern installation location, inside the
	 * 'textpattern' directory.
	 *
	 * Otherwise the path is relative to the current working directory
	 * which might be something entirely else. Never expect
	 * the currrent working directory point to Textpattern
	 * installation location. If you change the current working
	 * directory, remember to reset it. Forgetting to do so, may
	 * cause horrific accidents.
	 *
	 * @param  string|array $path Path to a directory
	 * @return bool
	 */

	public function read($path)
	{
		foreach ((array) $path as $directory)
		{
			$directory = realpath($this->path($directory));

			if (!file_exists($directory) || !is_dir($directory) || !is_readable($directory))
			{
				throw new rah_updater_exception('Unable read "'.basename($directory).'" directory.');
			}

			$iterator = new RecursiveDirectoryIterator($directory);
			$iterator = new RecursiveIteratorIterator($iterator);

			foreach ($iterator as $file)
			{
				if (pathinfo($file, PATHINFO_EXTENSION) === 'php' && is_file($file) && is_readable($file))
				{
					$this->files[basename($file, '.php')] = $file;
				}
			}

			uksort($this->files, 'version_compare');
			return true;
		}
	}

	/**
	 * Formats a path relative to the Textpattern installation.
	 *
	 * @param  string $path
	 * @return string
	 */

	public function path($path)
	{
		if (strpos($path, './') === 0)
		{
			return txpath . '/' . substr($path, 2);
		}

		if (strpos($path, '../') === 0)
		{
			return dirname(txpath) . '/' . substr($path, 3);
		}

		return $path;
	}
}

/**
 * Exception handler.
 *
 * Simply extends Exception.
 */

class rah_updater_exception extends Exception
{
}

/**
 * Implementation.
 */

class rah_updater_deployer
{
	/**
	 * Constructor.
	 */

	public function __construct()
	{
		register_callback(array($this, 'install'), 'plugin_lifecycle.rah_updater', 'installed');
		register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_updater', 'deleted');
		register_callback(array($this, 'endpoint'), 'textpattern');
	}

	/**
	 * Installer.
	 */

	public function install()
	{
		$position = 260;

		foreach (
			array(
				'rah_updater_path' => array('text_input', '../rah_updater'),
				'rah_updater_key'  => array('text_input', md5(uniqid(mt_rand(), true))),
			) as $name => $val
		)
		{
			set_pref($name, get_pref($name, $val[1]), 'rah_updater', PREF_PLUGIN, $val[0], $position);
			$position++;
		}
	}

	/**
	 * Uninstaller.
	 */

	public function uninstall()
	{
		safe_delete('txp_prefs', "name like 'rah\_updater\_%'");
	}

	/**
	 * Public callback hook endpoint.
	 *
	 * Can be used to manually invoke updater.
	 */

	public function endpoint()
	{
		extract(gpsa(array(
			'rah_updater',
		)));

		$path = get_pref('rah_updater_path');

		if ($path && get_pref('rah_updater_key') && get_pref('rah_updater_key') === $rah_updater)
		{
			try
			{
				$update = new rah_updater('rah_updater');
				$update->read(do_list($path));
				$update->run();
			}
			catch (Exception $e)
			{
				send_json_response(array('success' => false, 'error' => $e->getMessage()));
				die;
			}

			send_json_response(array('success' => true));
			die;
		}
	}
}

new rah_updater_deployer();