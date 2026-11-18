<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sg_search');
delete_option('sg_search_db_ver');
delete_option('sg_search_ready');
