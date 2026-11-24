<?php
/**
 * Plugin defaults — overwritten on every update, so nothing here is meant to be edited
 * by the site owner. Which address fields the postcode, prefecture, municipality and town
 * feed is chosen on the shop's Address lists screen and kept in the database.
 */
return [
    // Directory of the Japan Post postcode data shipped with the plugin (meta.json,
    // prefectures.json, postcodes/). Null = the bundled directory.
    'data_path' => null,
];
