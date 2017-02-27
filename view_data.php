<?php
/**
 * view_data.php is a web page that allows easy viewing of mysql database auction data.
 *
 * This page provides both the frontend and backend. The front end comes from resources/html/view_data.html
 * and resources/js/view_data.js. plotly and jquery are used to show graph the data. Requests are sent from the
 * front end to this php for data. The mysql query for data over time has been highly optimized for speed. The
 * raw data is sent back to the front end and processed (i.e. filtered) using javascript before graphing.
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
 * @todo Improve error handling on request validation
 */

if (!isset($_REQUEST["action"])) {
	echo file_get_contents("resources/html/view_data.html");
	exit();
}
else {
	$mysqli = new mysqli("127.0.0.1", "wow_ah_read", "wow_ah_read", "wow_ah");
	$mysqli->set_charset("utf8");

	switch($_REQUEST["action"]) {
		case "get_items":
			header("Content-Type: application/json");
			$query = "SELECT DISTINCT `item`,`item_id` FROM auctions ORDER BY `item`";
			if ($result = $mysqli->query($query)) {
				echo json_encode($result->fetch_all());
			}
			else {
				echo '{"error": "Error submitting query: ' . $query . " - " . $mysqli->error . '"}';
			}
			break;
		case "get_data":
			header("Content-Type: application/json");
			$error = "";
			$validation = array(
							"function"            => array("min", "max", "avg", "stddev"),
							"parameter"           => array("unitBuyout", "buyout", "unitBid", "bid", "quantity"),
							"condition_parameter" => array("none", "unitBuyout", "buyout", "unitBid", "bid", "quantity", "last_time"),
							"comparison"          => array("lt", "gt", "lte", "gte", "eq", "neq")
							);
			foreach ($validation as $paramname => $options) {
				if (!isset($_REQUEST[$paramname])) {
					$error = "Missing request parameters.";
					break;
				}
				else if (!in_array($_REQUEST[$paramname], $options)) {
					$error = "Invalid option specified for: $paramname.";
					break;
				}
			}
			if ($_REQUEST["condition_parameter"] == "last_time" && (!isset($_REQUEST["last_time"]) || !in_array($_REQUEST["last_time"], array("Short", "Medium", "Long", "Very long"))))
				$error = "Invalid time specified.";

			if (!isset($_REQUEST["item_id"]))
				$error = "No item name specified.";
			else
				$item_id = $mysqli->real_escape_string($_REQUEST["item_id"]);

			if ($error != "") {
				echo '{"error": "' . $error . '"}';
				exit();
			}

			$query = "SELECT unix_timestamp(t.time), {$_REQUEST["function"]}(`a`.`{$_REQUEST["parameter"]}`) FROM (SELECT ";
			if ($_REQUEST["parameter"] != "avg" && $_REQUEST["parameter"] != "stddev")
				$query .= "DISTINCT ";
			$query .= "{$_REQUEST["parameter"]}, `first_seen`, `last_seen` FROM `auctions` WHERE `item_id` = '$item_id' AND `first_seen` > DATE_SUB(CURRENT_DATE(), INTERVAL 2 WEEK)";
			if ($_REQUEST["condition_parameter"] != "none") {
				$comparisons = array("lt" => "<", "gt" => ">", "lte" => "<=", "gte" => ">=", "eq" => "=", "neq" => "!=");
				$query .= " AND `" . $_REQUEST["condition_parameter"] . "` " . $comparisons[$_REQUEST["comparison"]] . " ";
				if ($_REQUEST["condition_parameter"] == "last_time")
					$query .= "\"{$_REQUEST["last_time"]}\"";
				else if ($_REQUEST["condition_parameter"] == "quantity")
					$query .= intval($_REQUEST["quantity"]);
				else
					$query .= 10000 * intval($_REQUEST["gold"]) + 100 * intval($_REQUEST["silver"]) + intval($_REQUEST["copper"]);
			}
			else if ($_REQUEST["parameter"] == "buyout" || $_REQUEST["parameter"] = "unitBuyout") {
				$query .= " AND `unitBuyout` > 0"; //ignore bid only auctions
			}
			$query .= ") `a` JOIN (SELECT `time` FROM `times`) `t` ON (a.first_seen = t.time OR a.last_seen = t.time) AND a.first_seen <= t.time AND a.last_seen >= t.time GROUP BY `time` ORDER BY `time`";
			if ($result = $mysqli->query($query)) {
				$output = $result->fetch_all();
				if (count($output) > 0)
					echo json_encode(array_map(null, ...$output));
				else
					echo '{"error": "No results found."}';
			}
			else {
				echo '{"error": "Error submitting query: ' . $query . " - " . $mysqli->error . '"}';
			}
			break;
		default:
			break;
	}

	$mysqli->close();
}


?>
