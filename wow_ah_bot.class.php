<?php
/**
 * wow_ah_bot.class.php is an auction house price monitoring bot using the wow_ah api.
 *
 * Contains the main API class (wow_ah_bot\wow_ah_bot) and necessary helper classes, objects, and functions
 *
 * Copyright (C) 2016 Aram Akhavan <kaysond@hotmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package wow_ah_bot
 * @author  Aram Akhavan <kaysond@hotmail.com>
 * @link    https://github.com/kaysond/wow_ah_bot
 * @copyright 2016 Aram Akhavan
 */

namespace wow_ah_bot;

/** Whether the bot should log its actions */
define("wow_ah_bot\LOG_BOT", true);
/** Whether the log entries should be sent to stdout */
define("wow_ah_bot\LOGTOSTDOUT", true);
/** Number of times to attempt logging in before throwing an exception */
define("wow_ah_bot\LOGIN_FAILURE_LIMIT", 2);
/** Number of times to ignore search failures before throwing an exception */
define("wow_ah_bot\SEARCH_FAILURE_LIMIT", 8); //4 soft fails + 4 retries
/** Number of times ot ignore database update failures before throwing an exception */
define("wow_ah_bot\DATABASE_UPDATE_FAILURE_LIMIT", 2);
/** Interval, in seconds, between updates to the bot configuration (read from file) */
define("wow_ah_bot\CONFIG_UPDATE_INTERVAL", 5);
/** How many seconds to wait at minimum before flushing the log buffer to file (ignored if LOGTOSTDOUT is true) */
define("wow_ah_bot\LOG_BOT_INTERVAL", 30);
/** Local time at which the transaction limit should be reset */
define("wow_ah_bot\TRANSACTION_LIMIT_RESET_TIME", "02:00:00");

require_once("wow_ah.class.php");
require_once("object_from_array.class.php");

/**
 * The main bot class
 */
class wow_ah_bot {

	/** @var object mysqli instance */
	private $mysqli;
	/** @var object API instance */
	private $wow_ah;
	/** @var string The location of the log file */
	private $logfile;
	/** @var array A buffer for log entries so the disk access is not excessive */
	private $log_entries = array();
	/** @var integer Timestamp of the last log flush to file */
	private $last_log_time = 0;
	/** @var string The location of the configuration file */
	private $configfile;
	/**
	 * @var object representing the current bot configuration
	 * @see config
	 */
	private $config;
	/** @var boolean Whether the bot is running or not */
	private $running = false;
	/** @var string The name and server of the selected character */
	private $selected_character;
	/** 
	 * @var array An array of search objects representing the searches entered in the config file
	 * @see search
	 */
	private $searches;
	/**
	 * @var array An array of notification objects representing the criteria for notifications to be sent to recipients on the recipient list
	 * @see notification
	 */
	private $notifications;
	/**
	 * @var array An array of notification_recipient objects to whom notifications wil be sent
	 * @see notification_recipient
	 */
	private $notification_list;
	/**
	 * @var array An associative array whose keys are hashes of the notifications, and whose values are the timestamp of the last time when that notification was sent
	 */
	private $notification_history;
	/** @var boolean A flag indicating that the notification that the account is logged into the game has been sent */
	private $flag_notified_char_in_game = false;
	/** @var boolean A flag indicating that the notification that the transaction limit has been met for the day has been sent (currently not used since the bot only searches) */
	private $flag_notified_transaction_limit = false;

	/**
	 * Constructs the bot
	 *
	 * The constructor loads the configuration, initializes the mysqli object and the wow_ah object, and attempts to log in.
	 *
	 * @param string $configfile     Location of the configuration JSON file (see example.json or load_config_from_file() for the format)
	 * @param string $logfile        Location of the log file
	 * @param string $cookiefile     Location of the cookie file for the API
	 * @param string $apilogfile     Location of the log file for the API
	 * @param array  $browser_info   An associative array containing information about the browser the API will represent (see wow_ah\browser_info)
	 * @param string $mysql_server   The mysql server location
	 * @param string $mysql_db       The mysql database name
	 * @param string $mysql_username The mysql username
	 * @param string $mysql_password The mysql password
	 *
	 * @see wow_ah_bot::load_config_from_file()
	 * @see wow_ah\browser_info
	 */
	function __construct($configfile, $logfile, $cookiefile, $apilogfile, $browser_info, $mysql_server = "", $mysql_db = "", $mysql_username = "", $mysql_password = "") {
		$this->logfile = $logfile;
		Exception::set_logfile($this->logfile);
		$this->log("Initializing bot...");
		$this->configfile = $configfile;

		try {
			$this->log("Loading config.");
			$this->load_config_from_file($this->configfile);
			if ($this->config->store_to_db) {
				mysqli_report(MYSQLI_REPORT_ALL);
				$this->mysqli = new \mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_db);
				if ($this->mysqli->connect_errno)
					throw new Exception("Failed to connect to database. Error code: {$this->mysqli->connect_error}.");
				$this->mysqli->set_charset("utf8");
			}
			else {
				$this->mysqli = NULL;
			}
			$this->wow_ah = new \wow_ah\wow_ah($this->config->region, $this->config->lang, $cookiefile, $apilogfile, $browser_info);
			//If the login fails, give it one more try
			if (!$this->wow_ah->is_logged_in()) {
				$this->notify("Logging in...");
				$this->log("Logging in...");
				$start = time();
				try {
					$this->wow_ah->login($this->config->bnet_username, $this->config->bnet_password);
					$this->log(sprintf("Login took %d seconds.", time()-$start));
				}
				catch (\wow_ah\Exception $e) {
					$this->log("Login failed. Waiting 10 seconds and trying again.");
					sleep(10);
					$start = time();
					$this->wow_ah->login($this->config->bnet_username, $this->config->bnet_password);
					$this->log(sprintf("Login took %d seconds.", time()-$start));
				}
			}
			else {
				$this->log("Already logged in. Session restored.");
			}
			$this->select_character($this->config->character);
		}
		catch (Exception $e) {
			throw new Exception("Could not construct bot.", 0, $e);
		}
		catch (\wow_ah\Exception $e) {
			$e->log_last_http();
			throw new Exception("Could not construct API. See API log.", 0, $e);
		}
		catch (mysqli_sql_exception $e) {
			throw new Exception("Could not construct database driver. Error code: " . $e->getCode() . ".", 0 , $e);
		}

	}

	/**
	 * Runs the bot
	 *
	 * The bot runs a loop that performs the following steps:
	 * + Check if the account is still logged in, and attempt to log in if not (retries a number of times based on LOGIN_FAILURE_LIMIT)
	 * + Performs a search in the configuration. If it soft fails (i.e. a code 302 is returned), it will retry after 5 seconds. If it hard fails, it will restart all of the searches after 10 seconds.
	 * + Scans the current set of search results for satisfaction of any of the notification requirements, and sends notifications if necessary.
	 * + Repeats the search then notification scan for every search in the configuration. The searches are spread out evenly over the search interval specified in the configuration with some random fuzz time. All of the searches in one interval share the same timestamp (the beginning of the interval).
	 * + After the searches are complete, the current set of results is uploaded to the database if enabled. If the db update fails, the results are stored and it is attempted again after the next search.
	 * + The current set of results is cleared.
	 * + Checks if the account is logged into the game, and notifies the recipient list if necessary
	 * + Checks if the transaction limit is reached, and notifies the recipient list if neccessary (currently doesn't do anything)
	 * + Attempts to update the config from the file, if the appropriate amount of time has elapsed. If the update fails, the bot reverts to the last used config.
	 * + Checks if the config indicates to stop running the bot, and quits the loop if necessary
	 */
	public function run() {
		try {
			$this->running = true;
			$search_index = 0;
			$next_search = 0;
			$login_failures = 0;
			$database_update_failures = 0;
			$all_results = array();
			$results_to_update = array();
			$flagRetryDBUpdate = false;
			$last_config_update = 0;
			$search_failures = 0;
			$timestamp = date("Y-m-d H:i:s");
			while($this->config->run) {
				if (!$this->wow_ah->is_logged_in()) {
					$this->notify("Logging in...");
					$this->log("Logging in...");
					$start = time();
					try {
						$this->wow_ah->login($this->config->bnet_username, $this->config->bnet_password);
						$this->log(sprintf("Login took %d seconds.", time()-$start));
						$login_failures = 0;
					}
					catch (\wow_ah\Exception $e) {
						$login_failures++;
						if ($login_failures > LOGIN_FAILURE_LIMIT) {
							throw new Exception("Exceeded login retry limit.", 0, $e);
						}
						if (!($e->getCode() & wow_ah\Exception::ERROR_BUT_RETRY)) {
							$this->log("Login hard failed. Waiting 5 seconds and trying again.");
							sleep(5);
							continue;
						}
						else {
							$this->log("Login soft failed. Waiting 5 seconds and trying again.");
							sleep(5);
							continue;
						}
					}
				}

				if (time() > $next_search) {
					$this->log("Searching for " . ($this->searches[$search_index]->name == "" ? "<blank>" : $this->searches[$search_index]->name) . "...");
					$results = array();
					$start = time();
					try {
						$results = $this->wow_ah->search($this->searches[$search_index]->name, $this->searches[$search_index]->category,
														 $this->searches[$search_index]->minlevel, $this->searches[$search_index]->maxlevel,
														 $this->searches[$search_index]->quality, $this->searches[$search_index]->sort,
									   	   				 $this->searches[$search_index]->reverse, $this->searches[$search_index]->limit, $timestamp);
						$search_failures = 0;
						$next_search = $start + intdiv($this->config->search_interval * 60, count($this->searches)) - 3 + rand(0,6);
	   	   				$this->log(sprintf("Returned %d results in %d seconds.", count($results), time()-$start));
	   	   				$all_results = array_merge($all_results, $results);
	   	   				$search_index++;
   	   				}
   	   				catch (\wow_ah\Exception $e) {
   	   				 	$search_failures++;
   	   				 	if ($search_failures > SEARCH_FAILURE_LIMIT)
   	   				 		throw new Exception("Exceeded search failure limit.", 0, $e);
   	   				 	if ($e->getCode() & Exception::ERROR_BUT_RETRY) {
	   	   				 	$this->log("Search soft failed. Trying again in 5 seconds.");
	   	   				 	sleep(5);
	   	   				 	$start = time();
	   	   				 	try {
		   	   				 	$results = $this->wow_ah->search($this->searches[$search_index]->name, $this->searches[$search_index]->category,
																 $this->searches[$search_index]->minlevel, $this->searches[$search_index]->maxlevel,
																 $this->searches[$search_index]->quality, $this->searches[$search_index]->sort,
											   	   				 $this->searches[$search_index]->reverse, $this->searches[$search_index]->limit, $timestamp);
		   	   				 	$search_failures = 0;
		   	   				 	$next_search = $start + intdiv($this->config->search_interval * 60, count($this->searches)) - 3 + rand(0,6);
			   	   				$this->log(sprintf("Returned %d results in %d seconds.", count($results), time()-$start));
			   	   				$all_results = array_merge($all_results, $results);
			   	   				$search_index++;
		   	   				}
	   	   				 	catch (\wow_ah\Exception $e) {
   	   				 			$e->log_last_http();
	   	   				 		$search_failures++;
	   	   				 		if ($search_failures > SEARCH_FAILURE_LIMIT)
   	   				 				throw new Exception("Exceeded search failure limit.", 0, $e);
	   	   				 		$this->log("Retry failed. Restarting searches in 10s.");
	   	   				 		$all_results = array();
	   	   				 		$timestamp = date("Y-m-d H:i:s");
	   	   				 		$search_index = 0;
	   	   				 		$next_search = time() + 10;
	   	   				 	}
	   	   				}
	   	   				 else {
	   	   					$this->log("Search hard failed. Restarting searches in 10s.");
	   	   					$e->log_last_http();
	   	   					$all_results = array();
   	   				 		$timestamp = date("Y-m-d H:i:s");
   	   				 		$search_index = 0;
   	   				 		$next_search = time() + 10;
	   	   				}
   	   				}

   	   				 if ($database_update_failures > 0)
   	   				 	$flagRetryDBUpdate = true;

	 				$this->process_notifications($all_results);

				}

				//After every round of searches, clear the results, but first add them to be updated in the db if necessary
				if ($search_index >= count($this->searches)) {
					$search_index = 0;
					$timestamp = date("Y-m-d H:i:s");
					if ($this->config->store_to_db)
						$results_to_update = array_merge($results_to_update, $all_results);
					$all_results = array();
				}

				//Process db update
				if ($this->config->store_to_db && ($search_index == 0 || $flagRetryDBUpdate) && count($results_to_update) > 0) {
					$flagRetryDBUpdate = false;
					$start = time();
					try {
						$this->update_database($results_to_update);
						$this->log(sprintf("Updated %d database entries in %d seconds.", count($results_to_update), time()-$start));
						$database_update_failures = 0;
						$results_to_update = array();
					}
					catch (mysqli_sql_exception $e) {
						$database_update_failures++;
						if ($database_update_failures == DATABASE_UPDATE_FAILURE_LIMIT)
							throw new Exception("Could not update database. Exceeded database update retry limit. Last error code: " . $e->getCode() . ".");
						$this->log("Database update failed. Trying again after next search.");
					}
				}

				if (date("h:i:s") == TRANSACTION_LIMIT_RESET_TIME)
					$this->wow_ah->reset_transaction_count();

				if ($this->wow_ah->is_char_in_game()) {
					if (!$this->flag_notified_char_in_game) {
						$this->notify("Detected character is in game. Some bot functionality restricted.");
						$this->log("Detected character is in game. Some bot functionality restricted.");
						$this->flag_notified_char_in_game = true;
					}
				}
				else {
					$this->flag_notified_char_in_game = false;
				}

				if ($this->wow_ah->get_transaction_count() > \wow_ah\TRANSACTION_LIMIT) {
					if (!$this->flag_notified_transaction_limit) {
						$this->notify("Transaction limit reached. Some bot functionality restricted.");
						$this->log("Transaction limit reached. Some bot functionality restricted.");
						$this->flag_notified_transaction_limit = true;
					}
				}
				else {
					$this->flag_notified_transaction_limit = false;
				}

				sleep(1);
				$now = time();
				if ($now > $last_config_update + CONFIG_UPDATE_INTERVAL) {
					$this->load_config_from_file($this->configfile);
					$this->select_character($this->config->character);
					$last_config_update = $now;
				}
			} //while
			$this->running = false;
			$this->log("Killing bot due to config option.");
		}
		catch (\wow_ah\Exception $e) {
			$e->log_last_http();
			$this->running = false;
			$this->notify("Error limit exceeded. Killing bot.");
			throw new Exception("Error limit exceeded during bot operation.", 0, $e);
		}

	}

	/**
	 * Cleanly closes the bot
	 */
	public function close() {
		$this->wow_ah->close();
		if ($this->config->store_to_db)
			$this->mysqli->close();
		$this->flush_log();
	}

	/**
	 * Loads the config from the specified file
	 *
	 * The configuration file is a JSON object with four properties. "bot" is a config object. "searches" is an array
	 * of search objects. "notifications" is an array of notification objects. "notification_list" is an array of notification_recipient
	 * objects. When loading notifications, this function hashes the notification object and creates an entry in the history to keep track
	 * of the last time that notification was sent so the bot can wait a minimum interval. It attempts to keep track of the history of notifications
	 * that haven't changed when the config file is reloaded.
	 *
	 * @param  string $file config file location
	 *
	 * @see config
	 * @see search
	 * @see notification
	 * @see notification_recipient
	 *
	 */
	private function load_config_from_file($file) {
		try {
			if (file_exists($file))
				$file_contents = file_get_contents($file);
			else
				throw new Exception("Config file $file does not exist.");

			$config = json_decode($file_contents, true);

			if ($config == NULL)
				throw new Exception("Config file is not valid json.");

			//Make sure all of the arrays are well-formed
			if (!isset($config["bot"]))
				throw new Exception("Invalid config file. Missing bot config.");
			
			try {
				$this->config = new config($config["bot"]);
			}
			catch (Exception $e) {
				throw new Exception("Bot configuration is invalid. Ensure all required options exist.", 0, $e);
			}


			try {
				if (isset($config["searches"])) {
					$searches = array();
					foreach ($config["searches"] as $search) {
						$searches[] = new search($search);
					}
					$this->searches = $searches;
				}
			}
			catch (Exception $e) {
				throw new Exception("Encountered invalid search specification.", 0, $e);
			}


			try {
				if (isset($config["notifications"])) {
					$notifications = array();
					$oldhistory = $this->notification_history;
					$notification_history = array();
					foreach ($config["notifications"] as $notification) {
						$hash = hash("crc32", json_encode($notification), false);
						$notifications[] = new notification(array_merge($notification, array("hash" => $hash)));
						$notification_history[$hash] = isset($oldhistory[$hash]) ? $oldhistory[$hash] : 0;
					}
					$this->notifications = $notifications;
					$this->notification_history = $notification_history;
				}
			}
			catch (Exception $e) {
				throw new Exception("Encountered invalid notification specification.", 0, $e);
			}
			
			try {
				if (isset($config["notification_list"])) {
					$notification_list = array();
					foreach ($config["notification_list"] as $recipient) {
						$notification_list[] = new notification_recipient($recipient);
					}
					$this->notification_list = $notification_list;
				}
			}
			catch (Exception $e) {
				throw new Exception("Encountered invalid notification recipient specification.", 0 , $e);
			}

		}
		catch (Exception $e) {
			if (empty((array) $this->config))
				throw $e;
			else
				$this->log("Error loading config file: '" . $e->getMessage() . "'. Using last good config");
		}

	}

	/**
	 * Attempts to find a character with the given name and server and selects it in the API
	 *
	 * @param  string $character The character name and server name, separated by a hyphen
	 *
	 */
	private function select_character($character) {
		$character_list = $this->wow_ah->get_character_list();
		$flagCharSelected = false;
		$found_character = 0;
		foreach ($character_list as $index => $name) {
			if (stristr(strtolower($name), strtolower($character))) {
				$this->wow_ah->select_character($index);
				$flagCharSelected = true;
				$found_character = $index;
				break;
			}
		}
		if (!$flagCharSelected)
			throw new Exception("Could not select character $character. Character was not found.");
		else if($found_character != 0) {
			$this->selected_character = $character_list[$found_character];
			$this->log("Selected character {$character_list[$found_character]}.");
		}
	}

	/**
	 * Updates the database with the passed set of search results
	 *
	 * Each search result is added to the database. If the auction already exists, its `last_seen` field is updated.
	 * For every round of searches, all of the results will have the same timestamp, making it easy to track market
	 * conditions over time.
	 *
	 * @param  array $search_results An array of search result objects
	 *
	 */
	private function update_database($search_results) {
		foreach ($search_results as $result) {
			$query = "INSERT INTO `auctions` (`id`,`item`,`item_id`,`seller`,`quantity`,`first_time`,`last_time`,`bid`,`unitBid`,`buyout`,`unitBuyout`,`first_seen`,`last_seen`) VALUES ({$result->id},'{$result->item_name}',{$result->item_id},'{$result->seller}',{$result->quantity},'{$result->time}','{$result->time}',{$result->bid},{$result->unitBid},{$result->buyout},{$result->unitBuyout},'{$result->timestamp}','{$result->timestamp}') ON DUPLICATE KEY UPDATE `bid`={$result->bid},`unitBid`={$result->unitBid},`last_time`='{$result->time}',`last_seen`='{$result->timestamp}'";
			if (!$result = $this->mysqli->query($query))
				throw new mysqli_sql_exception($this->mysqli->error, $this->mysqli->errorno);
		}
	}

	/**
	 * Scans the passed set of search results to see if any of the notification conditions have been met and sends out the notifications
	 *
	 * For each notification, the function checks for search results that match the notification's item name. It then calculates the necessary
	 * metric (e.g. mean, min, max) of the desired parameter (e.g. unitBuyout) of auctions with the right name. This is then compared against
	 * the value specified in the notification (using the specified comparison operator). If the comparison is true, a notification is sent
	 * unless one has been sent too recently (based on the interval specified in the config file). The current time is updated in the notification
	 * history, or it is reset to 0 if the comparison is not true so that the next time the comparison is true, a notification is sent.
	 *
	 * @param  array $search_results An array of search result objects
	 *
	 * @see notification
	 *
	 */
	private function process_notifications($search_results) {
		if ($this->config->send_notifications && count($this->notifications) > 0) {
			$start = time();
			$notif_string = array();
			foreach ($this->notifications as $notification) {
				$params = array();
				$comparison = false;
				if ($notification->param == "unitBuyout" || $notification->param == "buyout") {
					foreach ($search_results as $result) {
						if (stristr($result->item_name, $notification->name) && isset($result->{$notification->param}) && $result->{$notification->param} > 0) {
							$params[] = $result->{$notification->param};
						}
					}
				}
				else {
					foreach ($results as $result) {
						if (strcasecmp($result->item_name, $notification->name) == 0 && isset($result->{$notification->param})) {
							$params[] = $result->{$notification->param};
						}
					}
				}
				if (count($params) > 0) {
					switch ($notification->metric) {
						case "mean":
							$metric = array_sum($params) / count($params);
							break;
						case "stddev":
							$mean = array_sum($params) / count($params);
							$errsq = arrray();
							foreach ($params as $param)
								$errsq[] = ($param-$mean)**2;
							$metric = sqrt(array_sum($err_sq));
							break;
						case "min":
							$metric = min($params);
							break;
						case "max":
							$metric = max($params);
							break;
					}
					switch ($notification->comparison) {
						case "<":
							if ($metric < $notification->value)
								$comparison = true;
							break;
						case "<=":
							if ($metric <= $notification->value)
								$comparison = true;
							break;
						case ">":
							if ($metric > $notification->value)
								$comparison = true;
							break;
						case ">=":
							if ($metric >= $notification->value)
								$comparison = true;
							break;
						case "==":
							if ($metric == $notification->value)
								$comparison = true;
							break;
						case "!=":
							if ($metric != $notification->value)
								$comparison = true;
							break;
					}

					if ($comparison) {
						$now = time();
						if ($now > $this->notification_history[$notification->hash] + 60 * $this->config->notification_interval) {
							switch ($notification->param) {
								case "unitBuyout":
								case "buyout":
								case "bid":
								case "unitBid":
									$notif_string[] = "{$notification->metric} of {$notification->name} {$notification->param} (" . \wow_ah\wow_ah::format_money_string($metric) . ") {$notification->comparison} " . \wow_ah\wow_ah::format_money_string($notification->value) .".";	
									break;
								default:
									$notif_string[] = "{$notification->metric} of {$notification->name} {$notification->param} ($metric) {$notification->comparison} {$notification->value}.";
							}							
							$this->notification_history[$notification->hash] = $now;
						}
					}
					else {
						//Reset the notification timer if the comparison isn't still true
						$this->notification_history[$notification->hash] = 0;
					}

				} //if count params > 0

			} //foreach notification
			if (count($notif_string) > 0) {
				$this->notify(implode("\n", $notif_string));
				$this->log(sprintf("Sent %d notifications in %ds.", count($notif_string) * count($this->notification_list), time()-$start));
			}
	
		} //if send notifications && count notifications > 0

	} //function process_notifications

	/**
	 * Sends a message to all users on the notification list
	 *
	 * Four types of notifications are supported.
	 * + nma: Notify My Android (Android push notification service). The identifier should be the api key.
	 * + prowl: An iOS push notification service. The identifier should be the api key.
	 * + custom: Sends a custom POST request. The identifier should be the desired url, where "%s" will be replaced by the message
	 * + email: Uses php's mail() to send an email. The identifier should be the email address
	 *
	 * @param  string $message The message to be sent
	 *
	 */
	private function notify($message) {
		if (count($this->notification_list) > 0) {
			foreach ($this->notification_list as $recipient) {
				$curl_post = false;
				switch ($recipient->type) {
					case "nma":
						$data = array(
							"apikey"      => $recipient->identifier,
							"application" => "wow_ah", 
							"event"       => "wow_ah notification",
							"description" => $message,
							"url"         => ""
						);
						$url = "https://www.notifymyandroid.com/publicapi/notify";
						$curl_post = true;
						break;
					case "prowl":
						$data = array(
							"apikey"      => $recipient->identifier,
							"application" => "wow_ah", 
							"event"       => "wow_ah notification",
							"description" => $message,
							"url"         => ""
						);
						$url = "https://api.prowlapp.com/publicapi/add";
						$curl_post = true;
						break;
					case "custom":
						$url = str_replace("%s", $message, $recipient->identifier);
						$data = array();
						$curl_post = true;
						break;
					case "email":
						break;
					default:
						throw new Exception("Unexpected notification type.");
				}
				if ($curl_post) {
					$ch = curl_init($url);
					curl_setopt($ch, CURLOPT_POST, true);
					curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					$response = curl_exec($ch);
					$info = curl_getinfo($ch);
					curl_close($ch);
					if ($response === false || $info["http_code"] != 200)
						$this->log("Could not send notification. Type: {$recipient->type}. Identifier: {$recipient->identifier}.");
				}
				else if ($recipient->type == "email") {
					if (!mail($recipient->identifier, "wow_ah notification", $message))
						$this->log("Could not send notification. Type: {$recipient->type}. Identifier: {$recipient->identifier}.");
				}

			} //foreach notification_list

		} //if count notification_list > 0

	} //function notify

	/**
	 * Add an entry to the log
	 *
	 * Log entries are buffered and flushed at a minimum specified interval (LOG_BOT_INTERVAL)
	 * to avoid a disk write bottleneck. The logs only get flushed
	 * when a new log entry is generated, so it is possible to lose entries if the BOT shuts down
	 * unexpectedly. flush_log() can be used to manually flush the buffer, and is automatically
	 * called by close().
	 *
	 * @param  string $entry The log entry
	 */
	private function log($entry) {
		if (LOG_BOT) {
			if (LOGTOSTDOUT) {
				echo date("[m-d-y H:i:s] ") . $entry . PHP_EOL;
			}
			else {
				$this->log_entries[] = date("[m-d-y H:i:s] ") . $entry . PHP_EOL;
				$now = time();
				if ($now > $this->last_log_time + LOG_API_INTERVAL) {
					file_put_contents($this->logfile, implode("", $this->log_entries), FILE_APPEND);
					$this->log_entries = array();
					$this->last_log_time = $now;
				}
			}
		}
	}

	/**
	 * Manually flushes the log buffer to disk
	 */
	public function flush_log() {
		if (LOG_BOT) {
			file_put_contents($this->logfile, implode("", $this->log_entries), FILE_APPEND);
			$this->log_entries = array();
			$this->last_log_time = time();
		}
	}

}

/**
 * A wow_ah_bot extension of object_from_array that throws wow_ah_bot\exceptions when errors are encountered
 *
 * @see \object_from_array
 */
class object_from_array extends \object_from_array {
	/** 
	 * Throws a wow_ah_bot\Exception with the \object_from_array error message
	 *
	 * @param string $message The message string from \object_from_array
	 */
	protected function error($message) {
		throw new Exception($message);
	}

}

/**
 * The bot's configuration
 */
class config extends object_from_array {
	/** @var string Character and server to attempt to use */
	public $character = "";
	/** @var string  Battle.net region to use as the subdomain for API requests (e.g. "us") */
	public $region = "";
	/** @var string Battle.net language (e.g. "en", used in API request urls as /wow/en/) */
	public $lang = "";
	/** @var string Battle.net username */
	public $bnet_username = "";
	/** @var string Battle.net password */
	public $bnet_password = "";
	/** @var boolean Whether to store search results to the database */
	public $store_to_db = false;
	/** @var boolean Whether the bot should be running */
	public $run = false;
	/** @var integer How often, in minutes, to run the whole set of searches */
	public $search_interval = 0;
	/** @var boolean Whether to send notifications */
	public $send_notifications = false;
	/** @var integer How often to wait before sending repeat notifications */
	public $notification_interval = 0;
}

/**
 * A search to perform
 */
class search extends object_from_array {
	/** @var array The user need not specify an item_id */
	protected static $optional = array("item_id");

	/** @var integer Item id (not necessary, but if present overrides name, category, minlevel, maxlevel, and quality) */
	public $item_id = 0;
	/** @var string Iitem name */
	public $name = "";
	/** @var string Category, subcategory, and sub-sub category, separated by commas (see categories.txt) (e.g. 0,2) */
	public $category = "";
	/** @var integer Minimum level */
	public $minlevel = 0;
	/** @var integer Maximum level */
	public $maxlevel = 0;
	/** @var integer Minimum item quality (0 = Poor, 1 = Common, 2 = Uncommon, 3 = Rare, 4 = Epic) */
	public $quality = 0;
	/** @var string How to sort results ("rarity", "quantity", "level", "ilvl", "time", "bid", "unitBid", "buyout", "unitBuyout") */
	public $sort = "";
	/** @var boolean True is descending, false is ascending */
	public $reverse = false;
	/** @var integer How many auctions to return, -1 for all (Armory limits to 200) */
	public $limit = 0;
}

/**
 * A criterion for which to send a notification
 *
 * @see wow_ah_bot::process_notifications()
 */
class notification extends object_from_array {
	/** @var string Item name */
	public $name = "";
	/** @var string Metric to use (mean, stddev, min, max)  */
	public $metric = "";
	/** @var string Parameter to evaluate (buyout, unitBuyout, bid, unitBid, quantity) */
	public $param = "";
	/** @var string Comparison operator to use (<, <=, >, >=, ==, !=) */
	public $comparison = "";
	/** @var integer Value to compare the metric against */
	public $value = 0;
	/** @var string Hash of the notification, for the history management (automatically generated) */
	public $hash = "";
}

/**
 * A recipient of the notifications
 *
 * @see wow_ah_bot::notify()
 */
class notification_recipient extends object_from_array {
	/** @var string Notification type (nma, prowl, custom, email) */
	public $type = "";
	/** @var string Recipient identifier (depends on the type, see notify()) */
	public $identifier = "";
}

/**
 * An extension of wow_ah\Exception to separate log files
 */
class Exception extends \wow_ah\Exception {
	/** @var string Location of the log file errors should be written to */
	protected static $logfile = "";

	/**
	 * Constructor for the exception
	 *
	 * @param string  $message  Error message
	 * @param integer $code     Error code (not used)
	 * @param object  $previous Previous exception in the chain
	 */
	public function __construct($message, $code = 0, $previous = NULL) {
		parent::__construct($message, array(), $code, $previous);
	}

}

?>