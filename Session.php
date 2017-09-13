<?php

// Transparent session handler.
// Licenced under the AGPLv3 or higher
// Copyright (c) 2017 Rob Thomas <xrobau@linux.com>
//
// Usage:
//   define MYSQLHOST, MYSQLUSER and MYSQLPASS (and optionally MYSQLDBNAME)
//   $s = new \xrobau\Session();
//
// Nothing else is required. After that, you can simply proceed as per normal
//
//   session_create();
//   $_SESSION['test'] = 'foo';
//   session_close();
//
// No code changes are needed apart from the defines and creating the object
// (which simply registers the handler)

namespace xrobau;

class Session {
	private static $db;

	private $host;
	private $user;
	private $pass;
	private $dbname;

	public function __construct() {
		$this->host = defined('MYSQLHOST') ? MYSQLHOST : 'localhost';
		$this->user = defined('MYSQLUSER') ? MYSQLUSER : '';
		$this->pass = defined('MYSQLPASS') ? MYSQLPASS : '';
		$this->dbname = defined('MYSQLDBNAME') ? MYSQLDBNAME : 'sessions';

		if (!is_object(self::$db)) {
			$db = new \PDO("mysql:host=".$this->host.";charset=utf8mb", $this->user, $this->pass);
			$this->createSessionTable();
		}
		session_set_save_handler(
			[ $this, "session_open" ],
			[ $this, "session_close" ],
			[ $this, "session_read" ],
			[ $this, "session_write" ],
			[ $this, "session_destroy" ],
			[ $this, "session_gc" ]
		);

		$this->db = self::$db;

		session_start();
	}

	private function createSessionTable($db) {
		$db->query('CREATE TABLE IF NOT EXISTS `'.$this->dbname.'` (
			`id` varchar(32) NOT NULL,
			`access` int(10) unsigned DEFAULT NULL,
			`data` text,
			PRIMARY KEY (`id`)
		) DEFAULT CHARSET=utf8mb');

	}

	// The open callback works like a constructor in classes and is executed
	// when the session is being opened. It is the first callback function
	// executed when the session is started automatically or manually with
	// session_start(). Return value is TRUE for success, FALSE for failure.
	public function session_open() {
		return true;
	}

	// The close callback works like a destructor in classes and is executed
	// after the session write callback has been called. It is also invoked
	// when session_write_close() is called. Return value should be TRUE for
	// success, FALSE for failure.
	public function session_close() {
		return true;
	}

	// The read callback must always return a session encoded (serialized)
	// string, or an empty string if there is no data to read.
	//
	// This callback is called internally by PHP when the session starts or
	// when session_start() is called. Before this callback is invoked PHP
	// will invoke the open callback.
	//
	// The value this callback returns must be in exactly the same serialized
	// format that was originally passed for storage to the write callback.
	// The value returned will be unserialized automatically by PHP and used
	// to populate the $_SESSION superglobal. While the data looks similar to
	// serialize() please note it is a different format which is speficied  <-- typo in the docs
	// in the session.serialize_handler ini setting.
	//
	// Bug report of typo:  https://bugs.php.net/bug.php?id=75198
	public function session_read($id) {
		static $prep = false;
		if (!$prep) {
			$prep = self::$db->prepare('SELECT `data` FROM `'.$this->dbname.'` WHERE id = ?');
		}

		$res = $prep->execute([$id])->fetchAll(PDO::FETCH_COLUMN);

		// PHP Null coalesce operator, requires php 7.0
		// return $res[0] ?? '';
		return isset($res[0]) ? $res[0] : '';
	}

	// The write callback is called when the session needs to be saved and closed.
	// This callback receives the current session ID a serialized version the
	// $_SESSION superglobal. The serialization method used internally by PHP
	// is specified in the session.serialize_handler ini setting.
	//
	// The serialized session data passed to this callback should be stored against
	// the passed session ID. When retrieving this data, the read callback must
	// return the exact value that was originally passed to the write callback.
	//
	// This callback is invoked when PHP shuts down or explicitly when session_write_close()
	// is called. Note that after executing this function PHP will internally execute
	// the close callback.
	//
	// Note: The "write" handler is not executed until after the output stream is closed.
	// Thus, output from debugging statements in the "write" handler will never be seen
	// in the browser. If debugging output is necessary, it is suggested that the debug
	// output be written to a file instead.

	public function session_write($id, $data){
		static $prep;
		if (!$prep) {
			$prep = self::$db->prepare('INSERT INTO `'.$this->dbname.'` (`id`, `access`, `data`) VALUES (?, ?, ?) ON DUPLICATE KEY
				UPDATE `access` = ?, `data` = ?');
		}

		$settings = [ $id, time(), $data, time(), $data ];
		return $prep->execute($settings);
	}

	// This callback is executed when a session is destroyed with session_destroy()
	// or with session_regenerate_id() with the destroy parameter set to TRUE.
	// Return value should be TRUE for success, FALSE for failure.
	public function session_destroy($id) {
		static $prep;
		if (!$prep) {
			$prep = self::$db->prepare('DELETE FROM `'.$this->dbname.'` WHERE `id` = ?');
		}
		$prep->execute([$id]);
		return true;
	}

	// The garbage collector callback is invoked internally by PHP periodically in
	// order to purge old session data. The frequency is controlled by
	// session.gc_probability and session.gc_divisor. The value of lifetime which is
	// passed to this callback can be set in session.gc_maxlifetime. Return value
	// should be TRUE for success, FALSE for failure.

	public function session_gc($lifetime) {
		static $prep;
		if (!$prep) {
			$prep = self::$db->prepare('DELETE FROM `'.$this->dbname.'` WHERE `access` < ?');
		}
		$prep->execute([ time() - $lifetime ]);
		return true;
	}
}

