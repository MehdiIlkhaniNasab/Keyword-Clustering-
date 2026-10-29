<?php
/**
 * Plugin Name:       منظومه — Manzoomeh by Ronagh
 * Description:       منظومه، کیورد کلاسترینگ هوشمند و فروش‌محور: پاک‌سازی کلمات، حذف برند رقبا، خوشه‌بندی بر اساس ۱۰ نتیجه اول گوگل، تشخیص نیت و نوع صفحه، کشف شکاف محتوایی و کنیبالیزیشن، اولویت‌بندی بر اساس بازگشت سرمایه، خروجی کیورد ریسرچ اکسل/گوگل شیت و ساخت تقویم محتوا.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Mehdi Ilkhani Nasab
 * License:           GPL-2.0-or-later
 * Text Domain:       manzoomeh
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

const MZM_VERSION    = '1.0.0';
const MZM_DB_VERSION = '1';
const MZM_CAP        = 'mzm_manage';

define( 'MZM_FILE', __FILE__ );
define( 'MZM_DIR', __DIR__ );
define( 'MZM_URL', plugin_dir_url( __FILE__ ) );

foreach ( array(
	'core/helpers',
	'core/jalali',
	'core/settings',
	'core/network',
	'core/install',
	'models/projects',
	'models/data',
	'connectors/google',
	'connectors/sheets',
	'connectors/llm',
	'connectors/serp',
	'connectors/store',
	'engine/lexicon',
	'engine/normalize',
	'engine/clean',
	'engine/precluster',
	'engine/serp-analysis',
	'engine/structure',
	'engine/score',
	'engine/gap',
	'engine/calendar',
	'engine/pipeline',
	'engine/edit',
	'export/xlsx',
	'export/xlsx-reader',
	'export/workbooks',
	'export/gsheet',
	'rest',
	'app/ui',
	'app/router',
	'app/actions',
) as $mzm_file ) {
	require_once MZM_DIR . '/inc/' . $mzm_file . '.php';
}
unset( $mzm_file );

register_activation_hook( __FILE__, 'mzm_install' );
register_deactivation_hook( __FILE__, 'mzm_deactivate' );
