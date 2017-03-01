# wow\_ah\_bot - An example price tracking and notification bot using wow_ah
wow\_ah\_bot is a simple bot by http://github.com/kaysond that executes searches of the auction house, optionally records the data, and optionally sends notifications when certain conditions are met. Included are a script to run the bot, a script to set up the database, and a php web page to visualize the collected data.
Errors are handled through a custom exception, and while everything has been tested, there are likely some scenarios that are not yet handled explicitly. Enabling logging can help debug.

Use at your own risk. Though use of wow_ah_bot is virtually undetectable, Blizzard may sanction your account for accessing the AH via an unofficial method.

## Features
* Saves login session between executions
* Attempts to re-login during operation when necessary
* Intelligently retries failed actions without sending excessive requests to the server
* Stores data to a mysql database
* Sends notifications (supports iOS and Android push, email, and user-defined POST requests)

## Documentation
Full documentation is in the docs folder. Also in the code.

## Requirements
* [wow_ah](https://github.com/kaysond/wow_ah)
* mysql (Optional, for storing auction data)
* Notification service (optional, for receiving mobile push notifications)
  * [Notify My Android](http://www.notifymyandroid.com/)
  * [Prowl](https://www.prowlapp.com/)

[Pre-compiled 32-bit and 64-bit Windows php binaries](https://www.apachelounge.com/viewtopic.php?t=6359)

## Operation
The bot can be started with [run_bot.php](run_bot.php). Generally, constructing the object requires locations for the config file and log files, mysql connection information, and information about the browser the API will mimic. The config file is a JSON object (see below) that specifies some operational configuration, the searches to run, and when to send notifications. The bot automatically logs the user in as necessary, then performs all of the searches. Searches are evenly spread out over the search interval, and all searches in a given interval are marked with the same time stamp, to give a snapshot of the market. After every search, the conditions for notifications are examined, and notifications are sent. Once all of the searches are complete, the data is stored to the database, the results are cleared, and the process repeats. If a search fails to execute, it is retried; if the database update fails, the results are stored and the update is retried later. If too many failures occur, an exception is thrown. Throughout operation, the config file is re-read intermittently (every 5s by default). This allows the bot configuration, searches, or notifications, to be updated without interrupting operation. It also allows for a clean shutdown by setting bot.run to false.

## Config File
See [example_config.json](example_config.json). The file should be a JSON object with 4 properties: bot, searches, notifications, notification_list.

#### bot
This property is an object with the following properties:

* character: the desired character to use and its server, separated by a hyphen (e.g. "leeroy-sargeras")
* bnet_username: Battle.net username
* bnet_password: Battle.net password
* region: Battle.net region, used as the subdomain (e.g. "us" -> "us.battle.net")
* lang: Battle.net language, used in the url (e.g. "en" -> "us.battle.net/wow/en")
* run: Set to true for the bot to run, false to shut down the bot cleanly
* search_interval: The time interval over which to complete all of the searches, in minutes. This should be conservative (default: 5min) so as not to send too many requests.
* store_to_db: True to store results to the mysql database. Requires the mysql information to be passed to the constructor.
* send_notifications: True to send notfications.
* notification_interval: The minimum time interval, in seconds, between sending the same notification twice

#### searches
This property is an array of search objects, who have the following properties:

* item_id: the id of the item to search; optional, but if present overrides name, category, minlevel, maxlevel, and quality (those must still be present but can be empty)
* name: item name to search
* category: category, sub-category, and sub-sub-category, separated by commas (see [categories.txt](categories.txt))
* minlevel: minimum level
* maxlevel: maximum level
* quality: minimum item quality (0 = Poor, 1 = Common, 2 = Uncommon, 3 = Rare, 4 = Epic)
* sort: how to sort results ("rarity", "quantity", "level", "ilvl", "time", "bid", "unitBid", "buyout", "unitBuyout")
* reverse: true is descending, false is ascending
* limit: how many auctions to return (Armory limits to 200)

Use -1 to ignore a search parameter.

#### notifications
This property is an array of notification objects, who have the following properties:

* name: the name of the item whose auctions should be scanned
* metric: metric to use for calculation (mean, stddev, min, max)
* param: parameter to evaluate (buyout, unitBuyout, bid, unitBid, quantity)
* comparison: comparison operator to use (<, <=, >, >=, ==, !=)
* value: value to compare the metric against (NB: monetary values are in copper)
The notifications are each evaluated as follows: after every search, the current result set is scanned for auctions whose item names match the specified name. For these auctions, the specified parameters (e.g. unitBuyout) are gathered and passed to the metric function (e.g. minimum). This value is placed on the left hand side of the comparison specified, and the value property is placed on the right hand side. This comparison is evaluated, and if true, a notification is sent.

For example: `{"name":"felwort","metric":"min","param":"unitBuyout","comparison":"<=","value":2500000}` will evaluate to true if the minimum of the unitBuyouts of all auctions of felwort scanned is less than 250 gold.

Note that the notifications can only operate on search results that are given, so it may not necessarily be an accurate measurement of the auction house. For example, if tracking the minimum price, make sure to include a search that is sorted by unitbuyout, ascending.

#### notification_list
This property is an array of notification recipients, who have the following properties:

* type: one of ("nma", "prowl", "email", "custom")
  * nma: Notify My Android (Android push notification service). The identifier should be the api key.
  * prowl: An iOS push notification service. The identifier should be the api key.
  * custom: Sends a custom POST request. The identifier should be the desired url, where "%s" will be replaced by the message
  * email: Uses php's mail() to send an email. The identifier should be the email address
* identifier: an identifier based on the notification type

## Additional Files

### run_bot.php
This is an example script to construct and run the bot.run

### create_db.php
Connects to a mysql database and creates a table with the proper schema, and a view for data visualization

### view_data.php
A web page that polls the database for data and plots it, allowing for various filtering methods.